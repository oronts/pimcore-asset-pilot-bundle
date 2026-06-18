<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\AssetReorganizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:reorganize-assets',
    description: 'Re-organize the objects that own the assets in a folder (asset-centric, for post-import)',
)]
class ReorganizeAssetsCommand extends Command
{
    public function __construct(
        private readonly AssetReorganizer $reorganizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('by-ids', null, InputOption::VALUE_REQUIRED, 'Re-organize the owners of these specific asset ids (comma-separated), instead of scanning a folder')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Source asset folder to scan (e.g. /Staging)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max assets to scan', '100')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Queue each owner via Messenger instead of running inline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $async = (bool) $input->getOption('async');
        $byIds = $input->getOption('by-ids');
        $folder = $input->getOption('folder');

        if ($byIds !== null && $folder !== null && $folder !== '') {
            $io->error('Provide either --by-ids or --folder, not both.');

            return Command::INVALID;
        }

        if ($byIds !== null) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $byIds)), static fn (int $id): bool => $id > 0));
            if ($ids === []) {
                $io->error('--by-ids must list one or more positive asset ids.');

                return Command::INVALID;
            }
            $result = $this->reorganizer->reorganizeAssets($ids, $async);
        } elseif ($folder !== null && $folder !== '') {
            $result = $this->reorganizer->reorganizeFolder(
                (string) $folder,
                max(1, (int) $input->getOption('limit')),
                $async,
            );
        } else {
            $io->error('Provide either --by-ids or --folder.');

            return Command::INVALID;
        }

        $io->title('Asset Pilot — Reorganize Assets');
        $io->definitionList(
            ['assets scanned' => (string) $result->assetsScanned],
            ['owner objects' => (string) $result->ownerObjects],
            ['organized' => (string) $result->organized],
            ['dispatched' => (string) $result->dispatched],
            ['skipped' => (string) $result->skipped],
            ['failed' => (string) $result->failed],
        );

        if ($result->failed > 0) {
            $io->warning(sprintf('%d owner object(s) failed to re-organize; see the log.', $result->failed));

            return Command::FAILURE;
        }

        $io->success('Reorganize complete.');

        return Command::SUCCESS;
    }
}
