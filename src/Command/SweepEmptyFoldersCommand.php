<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\EmptyFolderSweepService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:sweep-empty-folders',
    description: 'Find (and with --delete, remove) empty asset folders left behind by cleanup',
)]
class SweepEmptyFoldersCommand extends Command
{
    public function __construct(
        private readonly EmptyFolderSweepService $sweep,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Restrict the sweep to this folder subtree')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max empty folders to process', '500')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Delete the empty folders (default: report only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Empty Folder Sweep');

        $root = $input->getOption('folder');
        $limit = max(1, (int) $input->getOption('limit'));
        $found = $this->sweep->findEmpty($root !== null ? (string) $root : null, 1, $limit);

        if ($found['items'] === []) {
            $io->success('No empty folders found.');

            return Command::SUCCESS;
        }

        $io->table(['Folder', 'Path'], array_map(static fn (array $item): array => [(string) $item['id'], $item['path']], $found['items']));

        if (!$input->getOption('delete')) {
            $io->note(sprintf('%d empty folder(s). Re-run with --delete to remove them.', count($found['items'])));

            return Command::SUCCESS;
        }

        $result = $this->sweep->deleteEmpty(array_map(static fn (array $item): int => $item['id'], $found['items']));
        $io->definitionList(
            ['deleted' => (string) $result['deleted']],
            ['skipped' => (string) $result['skipped']],
            ['failed' => (string) $result['failed']],
        );

        if ($result['failed'] > 0) {
            $io->warning(sprintf('%d folder(s) could not be deleted; see the log.', $result['failed']));

            return Command::FAILURE;
        }

        $io->success(sprintf('Swept %d empty folder(s).', $result['deleted']));

        return Command::SUCCESS;
    }
}
