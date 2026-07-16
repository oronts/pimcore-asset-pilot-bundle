<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\BoundedIntegerOption;
use Oronts\AssetPilotBundle\Exception\OperationRecoveryPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\OperationRecoveryResult;
use Oronts\AssetPilotBundle\Service\OperationRecoveryCoordinatorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:recover-operations',
    description: 'Preview or reconcile stale move and revert journal entries without repeating mutations',
)]
final class RecoverOperationsCommand extends Command
{
    public function __construct(private readonly OperationRecoveryCoordinatorInterface $recovery)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum stale operations to inspect', '100')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Finalize the exact reviewed recovery scope')
            ->addOption('plan-token', null, InputOption::VALUE_REQUIRED, 'Signed token returned by the matching preview');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = BoundedIntegerOption::parse($input->getOption('limit'), 1, 1_000);
        if ($limit === null) {
            $io->error('--limit must be an integer between 1 and 1000.');

            return Command::INVALID;
        }
        $apply = (bool) $input->getOption('apply');
        $token = $input->getOption('plan-token');

        if ($apply && !is_string($token)) {
            $io->error('Applying requires --plan-token from a matching preview.');

            return Command::INVALID;
        }
        if (!$apply && $token !== null) {
            $io->error('--plan-token is only valid together with --apply.');

            return Command::INVALID;
        }

        if (!$apply) {
            $review = $this->recovery->preview($limit, ActorContext::system());
            if ($review->results === []) {
                $io->success('No stale operations require recovery.');

                return Command::SUCCESS;
            }

            $this->render($io, $review->results);
            $io->writeln('Plan token: <info>' . $review->planToken . '</info>');
            $io->note('No asset mutation or journal update was performed.');

            return Command::SUCCESS;
        }

        try {
            $review = $this->recovery->apply($limit, ActorContext::system(), $token);
        } catch (OperationRecoveryPlanException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $results = $review->results;
        $this->render($io, $results);
        $unresolved = $review->unresolvedCount();
        if ($unresolved > 0) {
            $io->warning(sprintf('%d operation(s) still require recovery.', $unresolved));

            return Command::FAILURE;
        }

        $io->success(sprintf('Resolved %d stale operation(s).', count($results)));

        return Command::SUCCESS;
    }

    /** @param list<OperationRecoveryResult> $results */
    private function render(SymfonyStyle $io, array $results): void
    {
        $io->table(
            ['Operation', 'Asset', 'Kind', 'Classification', 'Journal updated', 'Reason'],
            array_map(static fn (OperationRecoveryResult $result): array => [
                (string) $result->operationId,
                (string) $result->assetId,
                $result->kind->value,
                $result->status->value,
                $result->journalUpdated ? 'yes' : 'no',
                $result->message,
            ], $results),
        );
    }
}
