<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\BoundedIntegerOption;
use Oronts\AssetPilotBundle\Command\Support\UsesReviewedApplyPlan;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\QuarantineServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:quarantine-purge',
    description: 'Preview expired quarantined assets and purge the exact reviewed selection',
)]
class QuarantinePurgeCommand extends Command
{
    use UsesReviewedApplyPlan;

    public function __construct(
        private readonly QuarantineServiceInterface $quarantineService,
        private readonly ApplyPlanServiceInterface $applyPlans,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('grace-days', null, InputOption::VALUE_REQUIRED, 'Override the configured grace period (days)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Purge the exact reviewed selection (default: preview only)')
            ->addOption('plan-token', null, InputOption::VALUE_REQUIRED, 'Signed, single-use token returned by the matching preview');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $planToken = $input->getOption('plan-token');
        if (!$this->hasValidPlanControl($io, $apply, $planToken)) {
            return Command::INVALID;
        }

        $graceOption = $input->getOption('grace-days');
        $graceDays = $graceOption === null ? null : BoundedIntegerOption::parse($graceOption, 0, PHP_INT_MAX);
        if ($graceOption !== null && $graceDays === null) {
            $io->error('--grace-days must be a non-negative integer.');

            return Command::INVALID;
        }
        try {
            $preview = $this->quarantineService->previewPurge($graceDays);
        } catch (StaleApplyPlanException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        sort($preview['assetIds'], SORT_NUMERIC);

        $io->title('Asset Pilot — Quarantine Purge' . ($apply ? '' : ' (preview)'));
        if ($preview['assetIds'] === []) {
            if ($apply) {
                $io->error('The reviewed quarantine purge selection is no longer current. Preview again.');

                return Command::INVALID;
            }

            return $this->renderResult($io, $preview['result'], true);
        }

        $plan = $this->plan($preview);
        if (!$apply) {
            $this->renderPlanToken($io, $this->applyPlans->issue($plan));

            return $this->renderResult($io, $preview['result'], true);
        }

        if (!$this->claimPlan($io, (string) $planToken, $plan)) {
            return Command::INVALID;
        }

        try {
            $result = $this->quarantineService->purgePlanned(
                $preview['graceDays'],
                $preview['assetIds'],
                $plan->fingerprintMap(),
            );
        } catch (StaleApplyPlanException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        return $this->renderResult($io, $result, false);
    }

    /**
     * @param array{graceDays: int, assetIds: list<int>, config: array<string, mixed>, targets: list<ApplyPlanTarget>, result: array{purged: int, skipped: int, failed: int}} $preview
     */
    private function plan(array $preview): ApplyPlan
    {
        $assetIds = $preview['assetIds'];
        sort($assetIds, SORT_NUMERIC);
        $targets = $preview['targets'];
        usort($targets, static fn (ApplyPlanTarget $left, ApplyPlanTarget $right): int => strcmp($left->id, $right->id));

        return new ApplyPlan(
            'quarantine-purge-cli',
            ActorContext::system(),
            ['assetIds' => $assetIds, 'selector' => ['graceDays' => $preview['graceDays']]],
            $preview['config'],
            $targets,
        );
    }

    /** @param array{purged: int, skipped: int, failed: int} $result */
    private function renderResult(SymfonyStyle $io, array $result, bool $preview): int
    {
        $io->definitionList(
            [$preview ? 'would purge' : 'purged' => (string) $result['purged']],
            ['skipped' => (string) $result['skipped']],
            ['failed' => (string) $result['failed']],
        );

        if ($result['failed'] > 0) {
            $io->warning(sprintf('%d asset(s) failed to purge; see the log.', $result['failed']));

            return Command::FAILURE;
        }

        $io->success($preview ? 'Preview complete; no assets were deleted.' : 'Purge complete.');

        return Command::SUCCESS;
    }
}
