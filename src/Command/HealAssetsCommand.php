<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\BoundedIntegerOption;
use Oronts\AssetPilotBundle\Command\Support\UsesReviewedApplyPlan;
use Oronts\AssetPilotBundle\Command\Support\ValidatesCliBulkIds;
use Oronts\AssetPilotBundle\Enum\HealOutcome;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\HealResult;
use Oronts\AssetPilotBundle\Model\UndoHealResult;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetIntegrityServiceInterface;
use Oronts\AssetPilotBundle\Service\IntegrityHealFingerprintService;
use Oronts\AssetPilotBundle\Service\IntegrityHealLog;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:heal-assets',
    description: 'Preview or apply rollback of broken assets to their last renderable version',
)]
class HealAssetsCommand extends Command
{
    use ValidatesCliBulkIds;
    use UsesReviewedApplyPlan;

    public function __construct(
        private readonly AssetIntegrityServiceInterface $integrity,
        private readonly VersionRollbackHealerInterface $healer,
        private readonly ApplyPlanServiceInterface $applyPlans,
        private readonly IntegrityHealFingerprintService $healFingerprints,
        private readonly IntegrityHealLog $healLog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('by-ids', null, InputOption::VALUE_REQUIRED, 'Heal (or undo) only these specific asset ids (comma-separated)')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Restrict the broken-asset scan to this folder')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to an asset type')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to a file extension')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max assets to scan for breakage', '100')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the reviewed heal or undo; without this option the command only previews')
            ->addOption('plan-token', null, InputOption::VALUE_REQUIRED, 'Signed, single-use token returned by the matching preview')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Explicitly scan all assets up to --limit; otherwise a filter or --by-ids is required')
            ->addOption('undo', null, InputOption::VALUE_NONE, 'Preview or reverse the most recent heal of each --by-ids asset');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $planToken = $input->getOption('plan-token');
        if (!$this->hasValidPlanControl($io, $apply, $planToken)) {
            return Command::INVALID;
        }

        $byIds = $this->byIds($input, $io);
        if ($byIds === false) {
            return Command::INVALID;
        }

        if ($input->getOption('undo')) {
            return $this->undo($io, $input, $byIds, $apply, $planToken);
        }

        $io->title($apply ? 'Asset Pilot — Heal Assets' : 'Asset Pilot — Heal Assets (preview)');
        $scan = $this->scanCandidates($input, $io, $byIds);
        if ($scan === false) {
            return Command::INVALID;
        }

        return $this->healScan($io, $input, $scan, $byIds, $apply, $planToken);
    }

    /** @return list<int>|false|null */
    private function byIds(InputInterface $input, SymfonyStyle $io): array|false|null
    {
        $rawIds = $input->getOption('by-ids');
        if ($rawIds === null) {
            return null;
        }

        $ids = $this->validatedCsvIds($io, (string) $rawIds, '--by-ids');
        if ($ids === null) {
            return false;
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param list<int>|null $byIds
     * @return array{items: list<array{id: int, path: string, checker: string, reason: ?string}>, scanned: int, broken: int, page: int, limit: int, hasNext: bool}|false
     */
    private function scanCandidates(InputInterface $input, SymfonyStyle $io, ?array $byIds): array|false
    {
        if ($byIds !== null) {
            return $this->integrity->checkAssets($byIds);
        }

        $filters = array_filter([
            'folder' => $input->getOption('folder'),
            'type' => $input->getOption('type'),
            'extension' => $input->getOption('extension'),
        ], static fn ($value): bool => $value !== null);
        if (!$input->getOption('all') && $filters === []) {
            $io->error('Choose at least one filter, use --by-ids, or explicitly use --all.');

            return false;
        }

        $limit = BoundedIntegerOption::parse($input->getOption('limit'), 1, 1_000);
        if ($limit === null) {
            $io->error('--limit must be an integer between 1 and 1000.');

            return false;
        }

        return $this->integrity->findBroken($filters, 1, $limit);
    }

    /** @param array{items: list<array{id: int, path: string, checker: string, reason: ?string}>, scanned: int, broken: int, page: int, limit: int, hasNext: bool} $scan */
    private function healScan(
        SymfonyStyle $io,
        InputInterface $input,
        array $scan,
        ?array $byIds,
        bool $apply,
        mixed $planToken,
    ): int {
        if ($scan['broken'] === 0) {
            if ($apply) {
                $io->error('The reviewed heal selection is no longer current. Preview again.');

                return Command::INVALID;
            }
            $io->success(sprintf('No broken assets among %d checked.', $scan['scanned']));

            return Command::SUCCESS;
        }

        $assetIds = array_map(static fn (array $item): int => (int) $item['id'], $scan['items']);
        sort($assetIds, SORT_NUMERIC);
        $review = $this->healReview($assetIds, $this->planRequest($input, $byIds, $assetIds, false));
        if ($review === null) {
            $io->error('An asset changed while the heal preview was being built. Preview again.');

            return Command::INVALID;
        }

        if (!$apply) {
            $results = $review['results'];
            $this->renderPlanToken($io, $this->applyPlans->issue($review['plan']));
        } else {
            if (!$this->claimPlan($io, (string) $planToken, $review['plan'])) {
                return Command::INVALID;
            }
            try {
                $results = $this->healer->healPlannedBatch($assetIds, $this->planFingerprints($review['plan']));
            } catch (StaleApplyPlanException $e) {
                $io->error($e->getMessage());

                return Command::INVALID;
            }
        }

        [$tally, $rows, $observerWarnings] = $this->healResults($scan['items'], $results);
        $this->renderHealResults($io, $tally, $rows, $observerWarnings);

        return $this->completeHeal($io, $tally, !$apply);
    }

    /**
     * @param list<array{id: int, path: string, checker: string, reason: ?string}> $items
     * @param array<int, HealResult> $results
     * @return array{array<string, int>, list<array{string, string, string, string, string}>, list<string>}
     */
    private function healResults(array $items, array $results): array
    {
        $tally = array_fill_keys(array_map(static fn (HealOutcome $outcome): string => $outcome->value, HealOutcome::cases()), 0);
        $rows = [];
        $observerWarnings = [];
        foreach ($items as $item) {
            $result = $results[(int) $item['id']];
            ++$tally[$result->outcome->value];
            $rows[] = [
                (string) $item['id'],
                $item['path'],
                $result->outcome->value,
                $result->toVersion !== null ? (string) $result->toVersion : '-',
                $result->reason ?? '-',
            ];
            foreach ($result->observerWarnings as $warning) {
                $observerWarnings[] = sprintf('Asset %d: %s', (int) $item['id'], $warning);
            }
        }

        return [$tally, $rows, $observerWarnings];
    }

    /**
     * @param array<string, int> $tally
     * @param list<array{string, string, string, string, string}> $rows
     * @param list<string> $observerWarnings
     */
    private function renderHealResults(SymfonyStyle $io, array $tally, array $rows, array $observerWarnings): void
    {
        $io->table(['Asset', 'Path', 'Outcome', 'To version', 'Reason'], $rows);
        $io->definitionList(...array_map(static fn (string $key, int $value): array => [$key => (string) $value], array_keys($tally), array_values($tally)));
        foreach ($observerWarnings as $warning) {
            $io->warning($warning);
        }
    }

    /** @param array<string, int> $tally */
    private function completeHeal(SymfonyStyle $io, array $tally, bool $dryRun): int
    {
        $failed = $tally[HealOutcome::Unrecoverable->value]
            + $tally[HealOutcome::Unverifiable->value]
            + $tally[HealOutcome::Skipped->value];
        if ($failed > 0) {
            $io->warning($dryRun
                ? sprintf('%d asset(s) cannot currently be healed; review the reasons above.', $failed)
                : sprintf('%d asset(s) were not healed; review the reasons above and the integrity log.', $failed));

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Preview complete; no assets were changed.' : 'Heal complete.');

        return Command::SUCCESS;
    }

    private function undo(
        SymfonyStyle $io,
        InputInterface $input,
        ?array $byIds,
        bool $apply,
        mixed $planToken,
    ): int {
        if ($byIds === null) {
            $io->error('--undo requires --by-ids to name the assets to reverse.');

            return Command::INVALID;
        }

        $io->title($apply ? 'Asset Pilot — Undo Heal' : 'Asset Pilot — Undo Heal (preview)');
        $review = $this->undoReview($byIds, $this->planRequest($input, $byIds, $byIds, true));
        if ($review === null) {
            $io->error('An asset or its heal record changed while the undo preview was being built. Preview again.');

            return Command::INVALID;
        }
        if (!$apply) {
            $this->renderPlanToken($io, $this->applyPlans->issue($review['plan']));

            return $this->renderUndo($io, $byIds, $review['results'], true);
        }

        if (!$this->claimPlan($io, (string) $planToken, $review['plan'])) {
            return Command::INVALID;
        }

        return $this->renderUndo($io, $byIds, $this->undoResults($byIds, false), false);
    }

    /** @param list<int> $assetIds @return array<int, UndoHealResult> */
    private function undoResults(array $assetIds, bool $preview): array
    {
        $results = [];
        foreach ($assetIds as $assetId) {
            $results[$assetId] = $this->healer->undoDetailed($assetId, $preview);
        }

        return $results;
    }

    /** @param list<int> $assetIds @param array<int, UndoHealResult> $results */
    private function renderUndo(SymfonyStyle $io, array $assetIds, array $results, bool $preview): int
    {
        $successful = 0;
        $rows = [];
        foreach ($assetIds as $id) {
            $result = $results[$id];
            if ($result->isSuccessful()) {
                ++$successful;
            }
            $rows[] = [
                (string) $id,
                str_replace('_', ' ', $result->outcome->value),
                $result->reason ?? '-',
            ];
        }
        $io->table(['Asset', 'Outcome', 'Reason'], $rows);

        if ($successful !== count($assetIds)) {
            $io->warning(sprintf(
                $preview
                    ? '%d of %d heal(s) could currently be reversed; review the reasons above.'
                    : 'Reversed %d of %d heal(s); review the reasons above.',
                $successful,
                count($assetIds),
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf($preview ? 'All %d heal(s) can currently be reversed.' : 'Reversed all %d heal(s).', $successful));

        return Command::SUCCESS;
    }

    /**
     * @param list<int> $assetIds
     * @param array<string, mixed> $request
     * @param array<int, UndoHealResult> $results
     * @param array<string, string> $state
     * @param array<int, array{id: int, from_version: ?int, to_version: ?int}|null> $entries
     */
    private function undoPlan(array $assetIds, array $request, array $results, array $state, array $entries): ApplyPlan
    {
        $targets = array_map(function (int $assetId) use ($entries, $results, $state): ApplyPlanTarget {
            $targetId = 'asset:' . $assetId;

            return new ApplyPlanTarget(
                $targetId,
                $this->descriptorFingerprint([
                    'assetState' => $state[$targetId],
                    'dryRun' => $results[$assetId]->dryRun,
                    'healRecord' => $entries[$assetId],
                    'outcome' => $results[$assetId]->outcome->value,
                    'reason' => $results[$assetId]->reason,
                    'reasonCode' => $results[$assetId]->reasonCode?->value,
                ]),
            );
        }, $assetIds);

        return new ApplyPlan('heal-assets-undo', ActorContext::system(), $request, ['version' => 1], $targets);
    }

    /**
     * @param list<int> $assetIds
     * @param array<string, mixed> $request
     * @return array{plan: ApplyPlan, results: array<int, UndoHealResult>}|null
     */
    private function undoReview(array $assetIds, array $request): ?array
    {
        $beforeState = $this->healFingerprints->fingerprintMap($assetIds);
        $beforeEntries = $this->undoEntries($assetIds);
        $results = $this->undoResults($assetIds, true);
        $afterState = $this->healFingerprints->fingerprintMap($assetIds);
        $afterEntries = $this->undoEntries($assetIds);
        if ($beforeState !== $afterState || $beforeEntries !== $afterEntries) {
            return null;
        }

        return [
            'plan' => $this->undoPlan($assetIds, $request, $results, $afterState, $afterEntries),
            'results' => $results,
        ];
    }

    /** @param list<int> $assetIds @return array<int, array{id: int, from_version: ?int, to_version: ?int}|null> */
    private function undoEntries(array $assetIds): array
    {
        $entries = [];
        foreach ($assetIds as $assetId) {
            $entries[$assetId] = $this->healLog->findUndoable($assetId);
        }

        return $entries;
    }

    /**
     * @param list<int> $assetIds
     * @param array<string, mixed> $request
     * @return array{plan: ApplyPlan, results: array<int, HealResult>}|null
     */
    private function healReview(array $assetIds, array $request): ?array
    {
        $before = $this->healFingerprints->fingerprintMap($assetIds);
        $results = [];
        foreach ($assetIds as $assetId) {
            $results[$assetId] = $this->healer->previewById($assetId);
        }
        $after = $this->healFingerprints->fingerprintMap($assetIds);
        if ($before !== $after) {
            return null;
        }

        return [
            'plan' => new ApplyPlan(
                'heal-assets',
                ActorContext::system(),
                $request,
                $this->healFingerprints->planConfig(),
                $this->healFingerprints->targets($assetIds, $after, $results),
            ),
            'results' => $results,
        ];
    }

    /** @param list<int>|null $byIds @param list<int> $assetIds @return array<string, mixed> */
    private function planRequest(InputInterface $input, ?array $byIds, array $assetIds, bool $undo): array
    {
        $selector = $byIds === null
            ? [
                'all' => (bool) $input->getOption('all'),
                'extension' => $input->getOption('extension'),
                'folder' => $input->getOption('folder'),
                'limit' => max(1, (int) $input->getOption('limit')),
                'mode' => 'scan',
                'type' => $input->getOption('type'),
            ]
            : ['assetIds' => $byIds, 'mode' => 'asset_ids'];

        return ['assetIds' => $assetIds, 'mode' => $undo ? 'undo' : 'heal', 'selector' => $selector];
    }

    /** @return array<string, string> */
    private function planFingerprints(ApplyPlan $plan): array
    {
        $fingerprints = [];
        foreach ($plan->targets as $target) {
            $fingerprints[$target->id] = $target->fingerprint;
        }

        return $fingerprints;
    }

}
