<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\ValidatesCliBulkIds;
use Oronts\AssetPilotBundle\Service\AssetZipService;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Build a download archive from the CLI — for cron or a worker, so large sets never block a request.
 * Source is one of --asset-ids, --folder-id or --object-ids; the result is written to --output (a
 * local path or a mounted shared volume), reusing the same strategies and thumbnail options as the
 * REST endpoint.
 */
#[AsCommand(
    name: 'asset-pilot:download-zip',
    description: 'Build a zip of assets (by ids, folder, or owning objects) to a file — non-blocking, for cron/workers.',
)]
class DownloadZipCommand extends Command
{
    use ValidatesCliBulkIds;

    public function __construct(
        private readonly AssetZipService $zipService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('asset-ids', null, InputOption::VALUE_REQUIRED, 'Comma-separated asset ids to pack')
            ->addOption('folder-id', null, InputOption::VALUE_REQUIRED, 'Pack every asset under this folder id')
            ->addOption('object-ids', null, InputOption::VALUE_REQUIRED, 'Pack every asset referenced by these data object ids')
            ->addOption('non-recursive', null, InputOption::VALUE_NONE, 'With --folder-id, only direct children')
            ->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'Archive layout: flat, folder, type, or a custom strategy name')
            ->addOption('thumbnail', null, InputOption::VALUE_REQUIRED, 'Pack this image thumbnail config instead of the original')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Destination file path for the archive')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Replace an existing destination file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $destination = $this->destination($input, $io);
        if (is_int($destination)) {
            return $destination;
        }

        $options = new ZipBuildOptions(
            strategy: $this->stringOption($input, 'strategy'),
            thumbnail: $this->stringOption($input, 'thumbnail'),
        );
        $source = $this->source($input, $io);
        if ($source === false) {
            return Command::INVALID;
        }

        $result = $this->buildArchive($input, $io, $source, $options);
        if (is_int($result)) {
            return $result;
        }
        if ($result['path'] === null || $result['added'] === 0) {
            $io->warning('No downloadable assets matched the selection.');

            return Command::FAILURE;
        }

        return $this->writeArchive($io, $result, $destination);
    }

    private function destination(InputInterface $input, SymfonyStyle $io): string|int
    {
        $outputPath = (string) $input->getOption('output');
        if ($outputPath === '') {
            $io->error('--output is required.');

            return Command::INVALID;
        }
        if (file_exists($outputPath) && !$input->getOption('force')) {
            $io->error('The output file already exists. Re-run with --force to replace it.');

            return Command::FAILURE;
        }

        return $outputPath;
    }

    /** @return array{source: string, ids: list<int>}|false */
    private function source(InputInterface $input, SymfonyStyle $io): array|false
    {
        $sources = array_filter(['asset-ids', 'folder-id', 'object-ids'], static fn (string $option): bool => $input->getOption($option) !== null);
        if (count($sources) !== 1) {
            $io->error('Provide exactly one of --asset-ids, --folder-id or --object-ids.');

            return false;
        }

        $source = $sources[array_key_first($sources)];
        $ids = [];
        if ($source === 'asset-ids' || $source === 'object-ids') {
            $ids = $this->validatedCsvIds($io, (string) $input->getOption($source), '--' . $source);
            if ($ids === null) {
                return false;
            }
        }

        return ['source' => $source, 'ids' => $ids];
    }

    /**
     * @param array{source: string, ids: list<int>} $source
     * @return array{path: ?string, requested: int, added: int, skipped: int, truncated: false}|int
     */
    private function buildArchive(
        InputInterface $input,
        SymfonyStyle $io,
        array $source,
        ZipBuildOptions $options,
    ): array|int {
        try {
            return match ($source['source']) {
                'asset-ids' => $this->zipService->buildFromAssetIds($source['ids'], $options),
                'folder-id' => $this->zipService->buildFromFolder((int) $input->getOption('folder-id'), !$input->getOption('non-recursive'), $options),
                default => $this->zipService->buildFromObjects($source['ids'], $options),
            };
        } catch (\Throwable $e) {
            $io->error('Failed to build the archive: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    /** @param array{path: string, requested: int, added: int, skipped: int, truncated: false} $result */
    private function writeArchive(SymfonyStyle $io, array $result, string $outputPath): int
    {
        if (!@rename($result['path'], $outputPath) && !(@copy($result['path'], $outputPath) && @unlink($result['path']))) {
            @unlink($result['path']);
            $io->error('Could not write the archive to ' . $outputPath);

            return Command::FAILURE;
        }

        $io->success(sprintf('Wrote %d asset(s) (%d skipped) to %s', $result['added'], $result['skipped'], $outputPath));

        return Command::SUCCESS;
    }

    private function stringOption(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
