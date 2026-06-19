<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use Oronts\AssetPilotBundle\Service\Query\ByteFormat;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:find-duplicates',
    description: 'Report byte-identical assets. Use --scan first to (re)build the content-hash index.',
)]
class FindDuplicatesCommand extends Command
{
    public function __construct(
        private readonly DuplicateDetectionService $duplicates,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('scan', null, InputOption::VALUE_NONE, 'Index matching assets (compute content hashes) before reporting')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to this folder')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to an asset type')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to a file extension')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max assets to index per scan run', '1000')
            ->addOption('report-limit', null, InputOption::VALUE_REQUIRED, 'Max duplicate groups to report', '50')
            ->addOption('asset-id', null, InputOption::VALUE_REQUIRED, 'Report only the duplicate group containing this asset id (instead of all groups)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Duplicate Detection');

        if ($input->getOption('scan')) {
            $filters = array_filter([
                'folder' => $input->getOption('folder'),
                'type' => $input->getOption('type'),
                'extension' => $input->getOption('extension'),
            ], static fn ($value): bool => $value !== null);

            $stats = $this->duplicates->index($filters, max(1, (int) $input->getOption('limit')));
            $io->text(sprintf('Indexed %d asset(s) (%d scanned, %d skipped).', $stats['indexed'], $stats['scanned'], $stats['skipped']));
        }

        $assetId = $input->getOption('asset-id');
        if ($assetId !== null) {
            return $this->reportSingleAsset($io, (int) $assetId);
        }

        $groups = $this->duplicates->findDuplicates(1, max(1, (int) $input->getOption('report-limit')));
        if ($groups === []) {
            $io->success('No duplicate assets found in the index.');

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn ($group): array => [
                substr($group->checksum, 0, 12) . '…',
                (string) $group->count,
                ByteFormat::human($group->fileSize),
                implode(', ', $group->assetIds),
            ],
            $groups,
        );
        $io->table(['Hash', 'Copies', 'Size', 'Asset ids'], $rows);
        $io->warning(sprintf('%d duplicate group(s) of %d total in the index.', count($groups), $this->duplicates->countDuplicateGroups()));

        return Command::SUCCESS;
    }

    private function reportSingleAsset(SymfonyStyle $io, int $assetId): int
    {
        $group = $this->duplicates->groupForAsset($assetId);
        if ($group === null) {
            $io->success(sprintf('Asset %d has no byte-identical duplicates in the index (run --scan if it is not indexed yet).', $assetId));

            return Command::SUCCESS;
        }

        $io->table(['Hash', 'Copies', 'Size', 'Asset ids'], [[
            substr($group->checksum, 0, 12) . '…',
            (string) $group->count,
            ByteFormat::human($group->fileSize),
            implode(', ', $group->assetIds),
        ]]);

        return Command::SUCCESS;
    }
}
