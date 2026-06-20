<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\AssetZipService;
use Oronts\AssetPilotBundle\Support\BulkIds;
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
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Destination file path for the archive');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $outputPath = (string) $input->getOption('output');
        if ($outputPath === '') {
            $io->error('--output is required.');

            return Command::INVALID;
        }

        $options = new ZipBuildOptions(
            strategy: $this->stringOption($input, 'strategy'),
            thumbnail: $this->stringOption($input, 'thumbnail'),
        );

        $sources = array_filter(['asset-ids', 'folder-id', 'object-ids'], static fn (string $o): bool => $input->getOption($o) !== null);
        if (count($sources) !== 1) {
            $io->error('Provide exactly one of --asset-ids, --folder-id or --object-ids.');

            return Command::INVALID;
        }

        try {
            $result = match ($sources[array_key_first($sources)]) {
                'asset-ids' => $this->zipService->buildFromAssetIds($this->ids($input, 'asset-ids'), $options),
                'folder-id' => $this->zipService->buildFromFolder((int) $input->getOption('folder-id'), !$input->getOption('non-recursive'), $options),
                default => $this->zipService->buildFromObjects($this->ids($input, 'object-ids'), $options),
            };
        } catch (\Throwable $e) {
            $io->error('Failed to build the archive: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($result['path'] === null || $result['added'] === 0) {
            $io->warning('No downloadable assets matched the selection.');

            return Command::FAILURE;
        }

        if (!@rename($result['path'], $outputPath) && !(@copy($result['path'], $outputPath) && @unlink($result['path']))) {
            @unlink($result['path']);
            $io->error('Could not write the archive to ' . $outputPath);

            return Command::FAILURE;
        }

        $io->success(sprintf('Wrote %d asset(s) (%d skipped) to %s', $result['added'], $result['skipped'], $outputPath));

        return Command::SUCCESS;
    }

    /** @return int[] */
    private function ids(InputInterface $input, string $option): array
    {
        return BulkIds::fromCsv((string) $input->getOption($option));
    }

    private function stringOption(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
