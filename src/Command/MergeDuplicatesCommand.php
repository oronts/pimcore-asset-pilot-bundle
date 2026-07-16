<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\BoundedIntegerOption;
use Oronts\AssetPilotBundle\Command\Support\UsesReviewedApplyPlan;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:merge-duplicates',
    description: 'Consolidate a byte-identical duplicate group onto one canonical asset (preview by default; --apply to merge)',
)]
class MergeDuplicatesCommand extends Command
{
    use UsesReviewedApplyPlan;

    public function __construct(
        private readonly DuplicateDetectionService $duplicates,
        private readonly DuplicateMergeService $merge,
        private readonly ApplyPlanServiceInterface $applyPlans,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('checksum', null, InputOption::VALUE_REQUIRED, 'The content hash of the duplicate group to merge (from asset-pilot:find-duplicates)')
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Resume a prepared or interrupted duplicate merge run')
            ->addOption('canonical', null, InputOption::VALUE_REQUIRED, 'Asset id to keep as canonical (default: the lowest id in the group)')
            ->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'Disposition strategy for the copies (default: the configured one)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the exact reviewed merge (default: preview only)')
            ->addOption('plan-token', null, InputOption::VALUE_REQUIRED, 'Signed, single-use token returned by the matching preview');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Merge Duplicates');

        $runId = $input->getOption('run-id');
        if ($runId !== null) {
            return $this->resume($io, $input, $runId);
        }

        $apply = (bool) $input->getOption('apply');
        $planToken = $input->getOption('plan-token');
        if (!$this->hasValidPlanControl($io, $apply, $planToken)) {
            return Command::INVALID;
        }

        $checksum = (string) ($input->getOption('checksum') ?? '');
        if ($checksum === '') {
            $io->error('--checksum is required.');

            return Command::INVALID;
        }

        $strategy = $input->getOption('strategy');
        if ($strategy !== null && !in_array($strategy, $this->merge->availableStrategies(), true)) {
            $io->error(sprintf('Unknown strategy "%s". Available: %s.', $strategy, implode(', ', $this->merge->availableStrategies())));

            return Command::INVALID;
        }

        $group = $this->duplicates->groupForChecksum($checksum);
        if ($group === null) {
            $io->error('No duplicate group with at least two live assets for that checksum.');

            return Command::FAILURE;
        }

        $canonicalId = $this->canonicalId($io, $input->getOption('canonical'));
        if ($canonicalId === false) {
            return Command::INVALID;
        }
        $canonicalId ??= min($group->assetIds);
        if (!in_array($canonicalId, $group->assetIds, true)) {
            $io->error(sprintf('Canonical asset %d is not a member of this duplicate group.', $canonicalId));

            return Command::INVALID;
        }
        $resolvedStrategy = is_string($strategy) ? $strategy : $this->merge->defaultStrategyName();
        $plan = $this->plan($group, $canonicalId, $resolvedStrategy);

        if ($apply && !$this->claimPlan($io, (string) $planToken, $plan)) {
            return Command::INVALID;
        }

        $outcome = $this->merge->merge(
            $group,
            $canonicalId,
            $resolvedStrategy,
            !$apply,
            $apply ? $this->fingerprints($plan) : null,
        );

        $this->renderOutcome($io, $outcome);

        if (!$apply) {
            $this->renderPlanToken($io, $this->applyPlans->issue($plan));

            return Command::SUCCESS;
        }

        return $this->outcomeExitCode($io, $outcome);
    }

    private function resume(SymfonyStyle $io, InputInterface $input, mixed $runId): int
    {
        if (!is_string($runId) || preg_match('/^[a-f0-9]{32}$/D', $runId) !== 1) {
            $io->error('--run-id must be a 32-character lowercase hexadecimal operation run ID.');

            return Command::INVALID;
        }
        if (!(bool) $input->getOption('apply')) {
            $io->error('--run-id requires --apply because resuming may continue mutations.');

            return Command::INVALID;
        }
        if (is_string($input->getOption('plan-token')) && trim((string) $input->getOption('plan-token')) !== '') {
            $io->error('--plan-token is not used when resuming an existing operation run.');

            return Command::INVALID;
        }

        $outcome = $this->merge->resume($runId);
        $this->renderOutcome($io, $outcome);

        return $this->outcomeExitCode($io, $outcome);
    }

    private function canonicalId(SymfonyStyle $io, mixed $value): int|false|null
    {
        if ($value === null) {
            return null;
        }

        $canonicalId = BoundedIntegerOption::parse($value, 1, PHP_INT_MAX);
        if ($canonicalId === null) {
            $io->error('--canonical must be a positive integer.');

            return false;
        }

        return $canonicalId;
    }

    private function plan(DuplicateGroup $group, int $canonicalId, string $strategy): ApplyPlan
    {
        $assetIds = array_values($group->assetIds);
        sort($assetIds, SORT_NUMERIC);
        $targets = $this->merge->planTargets($group);
        usort($targets, static fn (ApplyPlanTarget $left, ApplyPlanTarget $right): int => strcmp($left->id, $right->id));
        $availableStrategies = $this->merge->availableStrategies();
        sort($availableStrategies, SORT_STRING);

        return new ApplyPlan(
            'duplicate-merge-cli',
            ActorContext::system(),
            [
                'assetIds' => $assetIds,
                'canonicalId' => $canonicalId,
                'checksum' => $group->checksum,
                'strategy' => $strategy,
            ],
            [
                'availableStrategies' => $availableStrategies,
                'defaultStrategy' => $this->merge->defaultStrategyName(),
            ],
            $targets,
        );
    }

    /** @return array<string, string> */
    private function fingerprints(ApplyPlan $plan): array
    {
        $fingerprints = [];
        foreach ($plan->targets as $target) {
            $fingerprints[$target->id] = $target->fingerprint;
        }

        return $fingerprints;
    }

    private function renderOutcome(SymfonyStyle $io, \Oronts\AssetPilotBundle\Merge\MergeOutcome $outcome): void
    {
        $io->text(sprintf('Canonical asset: %d', $outcome->canonicalId));
        if ($outcome->runId !== null) {
            $io->text(sprintf('Run ID: %s', $outcome->runId));
        }
        if ($outcome->status !== null) {
            $io->text(sprintf('Run status: %s', $outcome->status->value));
        }
        if ($outcome->dispositions !== []) {
            $io->table(
                ['Copy', 'Outcome', 'Reason'],
                array_map(
                    static fn ($disposition): array => [(string) $disposition->copyId, $disposition->outcome->value, $disposition->reason],
                    $outcome->dispositions,
                ),
            );
        }
    }

    private function outcomeExitCode(SymfonyStyle $io, \Oronts\AssetPilotBundle\Merge\MergeOutcome $outcome): int
    {
        foreach ($outcome->dispositions as $disposition) {
            if (!in_array($disposition->outcome, [DispositionOutcome::Deleted, DispositionOutcome::Quarantined], true)) {
                $io->error('Merge completed with one or more copies left unresolved.');

                return Command::FAILURE;
            }
        }

        $io->success('Merge complete.');

        return Command::SUCCESS;
    }
}
