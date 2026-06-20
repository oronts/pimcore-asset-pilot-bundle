<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\NormalizeFilenamesService;
use Oronts\AssetPilotBundle\Support\BulkIds;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:normalize-filenames',
    description: 'Rename assets whose filename is not a valid Pimcore key to the sanitized form (preview by default; --apply to rename)',
)]
class NormalizeFilenamesCommand extends Command
{
    public function __construct(
        private readonly NormalizeFilenamesService $normalizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('by-ids', null, InputOption::VALUE_REQUIRED, 'Normalize only these specific asset ids (comma-separated)')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to this folder')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to an asset type')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to a file extension')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max assets to scan', '100')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually rename (default: preview only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Normalize Filenames');

        $byIds = $input->getOption('by-ids');
        if ($byIds !== null) {
            $ids = BulkIds::fromCsv((string) $byIds);
            if ($ids === []) {
                $io->error('--by-ids must list one or more positive asset ids.');

                return Command::INVALID;
            }
        } else {
            $filters = array_filter([
                'folder' => $input->getOption('folder'),
                'type' => $input->getOption('type'),
                'extension' => $input->getOption('extension'),
            ], static fn ($value): bool => $value !== null);
            $ids = $this->normalizer->findCandidates($filters, max(1, (int) $input->getOption('limit')));
        }

        $apply = (bool) $input->getOption('apply');
        $result = $this->normalizer->normalize($ids, dryRun: !$apply);

        if ($result['changes'] !== []) {
            $io->table(
                ['Asset', 'From', 'To'],
                array_map(static fn (array $c): array => [(string) $c['id'], $c['from'], $c['to']], $result['changes']),
            );
        }

        // Show outcomes (ACL/content-ref skips matter in the preview too, not just on --apply).
        if ($result['failed'] > 0) {
            $io->warning(sprintf('%d asset(s) cannot be renamed:', $result['failed']));
            foreach (array_slice($result['errors'], 0, 20, true) as $id => $error) {
                $io->text(sprintf('  Asset %d: %s', $id, $error));
            }
        }

        if (!$apply) {
            $io->note($result['changes'] === []
                ? 'No filenames need normalizing.'
                : sprintf('%d filename(s) would be normalized. Re-run with --apply to rename.', count($result['changes'])));

            return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $io->success(sprintf('Normalized %d filename(s) (%d skipped).', $result['renamed'], $result['skipped']));

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
