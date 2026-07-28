<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\BoundedIntegerOption;
use Oronts\AssetPilotBundle\Command\Support\ReviewedSelectionConsolePresenter;
use Oronts\AssetPilotBundle\Command\Support\ValidatesCliBulkIds;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\ReviewedSelectionException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ReviewedSelectionResult;
use Oronts\AssetPilotBundle\Service\FailureReplayServiceInterface;
use Oronts\AssetPilotBundle\Service\Query\UtcSinceCutoff;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:replay-failures',
    description: 'Re-run the objects whose organization failed, from the audit log',
)]
class ReplayFailuresCommand extends Command
{
    use ValidatesCliBulkIds;

    public function __construct(
        private readonly FailureReplayServiceInterface $replay,
        private readonly ReviewedObjectOperationServiceInterface $reviewedOperations,
        private readonly ReviewedSelectionConsolePresenter $presenter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('object-id', null, InputOption::VALUE_REQUIRED, 'Replay only these specific object ids (comma-separated), instead of every failed object')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only failures at or after this date (e.g. "-7 days")')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'Only failures from this rule')
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'Only failures for this object class')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Queue re-organization via Messenger instead of running inline')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max distinct failed objects to replay', '100')
            ->addOption('plan-token', null, InputOption::VALUE_REQUIRED, 'Signed token returned by the matching preview')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the replay; without this option the command only previews');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Replay Failures');

        $async = (bool) $input->getOption('async');
        $apply = (bool) $input->getOption('apply');
        $planToken = $input->getOption('plan-token');
        if (!$this->hasValidPlanControl($io, $apply, $planToken)) {
            return Command::INVALID;
        }

        $filters = $this->filters($input, $io);
        if ($filters === false) {
            return Command::INVALID;
        }

        $limit = BoundedIntegerOption::parse($input->getOption('limit'), 1, 1_000);
        if ($limit === null) {
            $io->error('--limit must be an integer between 1 and 1000.');

            return Command::INVALID;
        }
        $objectIds = $this->replay->selectObjects($filters, $limit);
        $result = $this->runReviewedSelection($io, $objectIds, $filters, $limit, $async, $apply, $planToken);
        if (is_int($result)) {
            return $result;
        }

        return $this->report($io, $result, count($objectIds), $async, $apply);
    }

    private function hasValidPlanControl(SymfonyStyle $io, bool $apply, mixed $planToken): bool
    {
        if ($apply && (!is_string($planToken) || trim($planToken) === '')) {
            $io->error('Applying requires --plan-token from a matching preview.');

            return false;
        }
        if (!$apply && is_string($planToken) && trim($planToken) !== '') {
            $io->error('--plan-token is only valid together with --apply.');

            return false;
        }

        return true;
    }

    /** @return array<string, mixed>|false */
    private function filters(InputInterface $input, SymfonyStyle $io): array|false
    {
        $objectIds = $input->getOption('object-id');
        if ($objectIds !== null) {
            $ids = $this->validatedCsvIds($io, (string) $objectIds, '--object-id');
            if ($ids === null) {
                return false;
            }
            sort($ids, SORT_NUMERIC);
            $objectIds = $ids;
        }

        $since = $input->getOption('since');
        if ($since !== null) {
            try {
                $since = UtcSinceCutoff::parse((string) $since);
            } catch (\Exception) {
                $io->error(sprintf('Could not parse --since value "%s".', $since));

                return false;
            }
        }

        return array_filter([
            'object_ids' => $objectIds,
            'since' => $since,
            'rule_name' => $input->getOption('rule'),
            'object_class' => $input->getOption('class'),
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @param list<int>            $objectIds
     * @param array<string, mixed> $filters
     */
    private function runReviewedSelection(
        SymfonyStyle $io,
        array $objectIds,
        array $filters,
        int $limit,
        bool $async,
        bool $apply,
        mixed $planToken,
    ): ReviewedSelectionResult|int {
        try {
            return $this->reviewedOperations->execute(
                OperationRunKind::Replay,
                $objectIds,
                ['filters' => $filters, 'limit' => $limit],
                TriggerType::Manual,
                !$apply,
                $async,
                $planToken,
                ActorContext::system(),
            );
        } catch (ReviewedSelectionException $e) {
            return $this->presenter->renderError($io, $e);
        }
    }

    private function report(SymfonyStyle $io, ReviewedSelectionResult $result, int $candidates, bool $async, bool $apply): int
    {
        $this->presenter->render($io, $result, ['candidates' => $candidates]);

        if ($result->failed > 0) {
            $io->warning(sprintf('%d object(s) failed to re-organize; see the log.', $result->failed));

            return Command::FAILURE;
        }

        $io->success($apply ? ($async ? 'Replay queued.' : 'Replay complete.') : 'Preview complete; no assets were changed.');

        return Command::SUCCESS;
    }
}
