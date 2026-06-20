<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\ValidatesCliBulkIds;
use Oronts\AssetPilotBundle\Service\AssetIntegrityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:check-integrity',
    description: 'Detect assets whose binary no longer renders (scans up to --limit; filter by --folder/--type)',
)]
class CheckIntegrityCommand extends Command
{
    use ValidatesCliBulkIds;

    public function __construct(
        private readonly AssetIntegrityService $integrity,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('by-ids', null, InputOption::VALUE_REQUIRED, 'Check only these specific asset ids (comma-separated), instead of scanning')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to this asset folder')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Restrict to an asset type (image, document, ...)')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Restrict to a file extension')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max assets to scan', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Integrity Check');

        $byIds = $input->getOption('by-ids');
        if ($byIds !== null) {
            $ids = $this->validatedCsvIds($io, (string) $byIds, '--by-ids');
            if ($ids === null) {
                return Command::INVALID;
            }
            $result = $this->integrity->checkAssets($ids);
        } else {
            $filters = array_filter([
                'folder' => $input->getOption('folder'),
                'type' => $input->getOption('type'),
                'extension' => $input->getOption('extension'),
            ], static fn ($value): bool => $value !== null);

            $result = $this->integrity->findBroken($filters, 1, max(1, (int) $input->getOption('limit')));
        }

        if ($result['broken'] === 0) {
            $io->success(sprintf('No broken assets among %d scanned.', $result['scanned']));

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn (array $item): array => [(string) $item['id'], $item['checker'], $item['path'], (string) $item['reason']],
            $result['items'],
        );
        $io->table(['Asset', 'Checker', 'Path', 'Reason'], $rows);
        $io->warning(sprintf('%d broken asset(s) among %d scanned.', $result['broken'], $result['scanned']));

        return Command::FAILURE;
    }
}
