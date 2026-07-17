<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\DependencyProjectionRebuilderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:rebuild-dependency-projection',
    description: 'Build or resume the indexed dependency projection in a bounded batch',
)]
class RebuildDependencyProjectionCommand extends Command
{
    public function __construct(
        private readonly DependencyProjectionRebuilderInterface $rebuilder,
        private readonly int $defaultRebuildBatchSize,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum sources processed by this invocation.', (string) $this->defaultRebuildBatchSize)
            ->addOption('restart', null, InputOption::VALUE_NONE, 'Discard the persisted cursor and start a new generation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1 || $limit > 10000) {
            $io->error('--limit must be an integer between 1 and 10000.');

            return Command::INVALID;
        }

        try {
            $result = $this->rebuilder->rebuildBatch($limit, (bool) $input->getOption('restart'));
        } catch (\Throwable) {
            $io->error('Dependency projection rebuild failed. Run asset-pilot:health and inspect the server log.');

            return Command::FAILURE;
        }

        $status = $result->status;
        $io->table(['Processed', 'Failed', 'State', 'Generation', 'Cursor', 'Dirty', 'Sources', 'Edges'], [[
            $result->processed,
            $result->failed,
            $status->state->value,
            $status->generation,
            $status->cursorType === null ? '-' : $status->cursorType . ':' . $status->cursorId,
            $status->dirtySources,
            $status->sourceCount,
            $status->edgeCount,
        ]]);

        if ($result->completed) {
            $io->success('Dependency projection is complete and safe for destructive reference checks.');

            return Command::SUCCESS;
        }
        if ($result->failed > 0) {
            $io->warning('Some sources remain dirty. Fix the reported source errors, then rerun this command.');

            return Command::FAILURE;
        }

        $io->note('The bounded batch completed. Rerun the command until the projection state is ready.');

        return Command::SUCCESS;
    }
}
