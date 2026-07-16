<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\BoundedIntegerOption;
use Oronts\AssetPilotBundle\Command\Support\ValidatesCliBulkIds;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetMutationFingerprintService;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\Query\ByteFormat;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:cleanup-unused',
    description: 'Preview unused assets and apply an exact reviewed cleanup plan',
)]
class CleanupUnusedCommand extends Command
{
    use ValidatesCliBulkIds;

    public function __construct(
        private readonly UnusedAssetFinderInterface $unusedAssetFinder,
        private readonly LoopGuard $loopGuard,
        private readonly AssetMutationFingerprintService $fingerprints,
        private readonly ApplyPlanServiceInterface $applyPlans,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('by-ids', null, InputOption::VALUE_REQUIRED, 'Act on these specific asset ids (comma-separated) instead of scanning. Each is still re-verified as unused and permission-checked.')
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Assets modified before this date (e.g. "2024-01-01", "-90 days", "-6 months")')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Assets modified after this date')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by asset type, comma-separated (e.g. "image,document")')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Filter by file extension, comma-separated (e.g. "pdf,png,jpg")')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Limit to assets in this folder path (e.g. "/uploads/temp")')
            ->addOption('action', null, InputOption::VALUE_REQUIRED, 'Action to preview or apply: "delete" or "move" (default: delete)', 'delete')
            ->addOption('move-to', null, InputOption::VALUE_REQUIRED, 'Target folder when action=move (e.g. "/archive/unused")')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the reviewed action; without this option the command only previews')
            ->addOption('plan-token', null, InputOption::VALUE_REQUIRED, 'Signed, single-use token returned by the matching preview')
            ->addOption('confirm-delete', null, InputOption::VALUE_NONE, 'Required with --apply --action=delete to confirm irreversible deletion')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Explicitly select all unused assets; otherwise a filter or --by-ids is required')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Process assets in batches of this size', '100')
            ->addOption('max-assets', null, InputOption::VALUE_REQUIRED, 'Maximum assets allowed in one scanned run', '1000')
            ->setHelp(<<<'HELP'
Find and clean up assets that are not referenced by any Pimcore data object or document.

<info>Examples:</info>

    Preview unused image assets older than 90 days:
    <comment>bin/console asset-pilot:cleanup-unused --before="-90 days" --type=image</comment>

  Apply a reviewed PDF deletion using the token printed by the preview:
    <comment>bin/console asset-pilot:cleanup-unused --before="-6 months" --extension=pdf --apply --confirm-delete --plan-token='v1...'</comment>

  Apply a reviewed move from /uploads/temp to /archive:
    <comment>bin/console asset-pilot:cleanup-unused --folder=/uploads/temp --action=move --move-to=/archive/unused --apply --plan-token='v1...'</comment>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getOption('action');
        $batchSize = BoundedIntegerOption::parse($input->getOption('batch-size'), 1, 1_000);
        $maxAssets = BoundedIntegerOption::parse($input->getOption('max-assets'), 1, 1_000);
        if ($batchSize === null || $maxAssets === null) {
            $io->error('--batch-size and --max-assets must be integers between 1 and 1000.');

            return Command::INVALID;
        }
        $moveTo = (string) $input->getOption('move-to');
        $apply = (bool) $input->getOption('apply');
        $planToken = $input->getOption('plan-token');

        if (!$this->hasValidPlanControl($io, $apply, $planToken)) {
            return Command::INVALID;
        }

        $invalid = $this->validateAction($io, $input, $action, $moveTo, $apply);
        if ($invalid !== null) {
            return $invalid;
        }

        if (($byIds = $input->getOption('by-ids')) !== null) {
            $conflict = $this->byIdsConflict($input);
            if ($conflict !== null) {
                $io->error(sprintf('--%s cannot be combined with --by-ids (which names the assets explicitly).', $conflict));

                return Command::FAILURE;
            }

            return $this->runForIds($io, $output, (string) $byIds, $action, $moveTo, $batchSize, $apply, $planToken);
        }

        return $this->runForSelector($io, $output, $input, $action, $moveTo, $batchSize, $maxAssets, $apply, $planToken);
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

    private function validateAction(SymfonyStyle $io, InputInterface $input, string $action, string $moveTo, bool $apply): ?int
    {
        if (!in_array($action, ['delete', 'move'], true)) {
            $io->error('Invalid action. Use "delete" or "move".');

            return Command::FAILURE;
        }
        if ($action === 'move' && $moveTo === '') {
            $io->error('The --move-to option is required when action=move.');

            return Command::FAILURE;
        }
        if ($apply && $action === 'delete' && !$input->getOption('confirm-delete')) {
            $io->error('Irreversible deletion requires --confirm-delete together with --apply.');

            return Command::INVALID;
        }

        return null;
    }

    private function byIdsConflict(InputInterface $input): ?string
    {
        foreach (['before', 'after', 'type', 'extension', 'folder'] as $scanFilter) {
            if ($input->getOption($scanFilter) !== null) {
                return $scanFilter;
            }
        }

        return null;
    }

    private function runForSelector(SymfonyStyle $io, OutputInterface $output, InputInterface $input, string $action, string $moveTo, int $batchSize, int $maxAssets, bool $apply, mixed $planToken): int
    {
        $selectorOptions = ['before', 'after', 'type', 'extension', 'folder'];
        $hasSelector = array_any($selectorOptions, static fn (string $option): bool => $input->getOption($option) !== null);
        if (!$input->getOption('all') && !$hasSelector) {
            $io->error('Choose at least one filter, use --by-ids, or explicitly use --all.');

            return Command::INVALID;
        }

        $filters = $this->buildFilters($input);
        try {
            $totalCount = $this->unusedAssetFinder->countUnused($filters);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if ($totalCount === 0) {
            if ($apply) {
                $io->error('The reviewed unused-asset selection is no longer current. Preview again.');

                return Command::INVALID;
            }
            $io->success('No unused assets found matching the given criteria.');

            return Command::SUCCESS;
        }

        $this->renderSelection($io, $filters, $totalCount);

        if ($totalCount > $maxAssets) {
            $io->error(sprintf('The current scope contains %d assets, above --max-assets=%d. Narrow the selector and review another bounded batch.', $totalCount, $maxAssets));

            return Command::INVALID;
        }

        try {
            $ids = $this->collectUnusedIds($filters, $batchSize, $totalCount);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $request = ['action' => $action, 'filters' => $filters, 'mode' => 'selector', 'moveTo' => $moveTo];

        return $apply
            ? $this->applySelector($io, $output, $ids, $request, $action, $moveTo, $batchSize, $planToken)
            : $this->previewSelector($io, $output, $filters, $ids, $request, $action, $moveTo, $totalCount);
    }

    /** @param array<string, mixed> $filters */
    private function renderSelection(SymfonyStyle $io, array $filters, int $totalCount): void
    {
        $io->title('Asset Pilot — Unused Asset Cleanup');
        $io->text(sprintf('Found <info>%d</info> unused asset(s) matching criteria.', $totalCount));
        if ($filters !== []) {
            $io->text('Filters: ' . json_encode($filters, JSON_UNESCAPED_SLASHES));
        }
    }

    /** @param array<string, mixed> $filters @param list<int> $ids @param array<string, mixed> $request */
    private function previewSelector(SymfonyStyle $io, OutputInterface $output, array $filters, array $ids, array $request, string $action, string $moveTo, int $totalCount): int
    {
        try {
            $this->renderDryRunPreview($io, $output, $filters, $action, $moveTo, $totalCount);
            $this->renderPlanToken($io, $this->plan($ids, $request)['plan']);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @param list<int> $ids @param array<string, mixed> $request */
    private function applySelector(SymfonyStyle $io, OutputInterface $output, array $ids, array $request, string $action, string $moveTo, int $batchSize, mixed $planToken): int
    {
        $io->text(sprintf('Action: <comment>%s</comment> | Batch size: %d', $action, $batchSize));
        $io->newLine();

        try {
            $review = $this->plan($ids, $request);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $rejection = $this->claimPlan($io, (string) $planToken, $review['plan']);
        if ($rejection !== null) {
            return $rejection;
        }
        $result = $this->processIds($output, $ids, $action, $moveTo, $batchSize, $review['fingerprints']);
        $io->newLine(2);
        $this->renderSummary($io, $action, $result);

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $filters
     */
    protected function renderDryRunPreview(SymfonyStyle $io, OutputInterface $output, array $filters, string $action, string $moveTo, int $totalCount): void
    {
        $io->note('DRY RUN — no changes will be made.');

        $result = $this->unusedAssetFinder->findUnused($filters, 1, min($totalCount, 50));
        $expected = min($totalCount, 50);
        if (($result['total'] ?? null) !== $totalCount || count($result['items']) !== $expected) {
            throw new \RuntimeException('The unused-asset preview changed or could not be read completely; no action was taken.');
        }
        $table = new Table($output);
        $table->setHeaders(['ID', 'Path', 'Type', 'Size', 'Modified', 'Outcome']);

        $would = 0;
        foreach ($result['items'] as $item) {
            $reason = $this->previewMutation((int) $item['id'], $action);
            if ($reason === null) {
                ++$would;
            }
            $table->addRow([
                $item['id'],
                $item['full_path'],
                $item['type'],
                ByteFormat::human((int) $item['file_size']),
                $item['modified_at'] ?? '-',
                $reason ?? ($action === 'move' ? 'would move' : 'would delete'),
            ]);
        }

        $table->render();

        if ($totalCount > 50) {
            $io->text(sprintf('... and %d more.', $totalCount - 50));
        }

        $io->newLine();
        $io->text(sprintf(
            'Of the %d previewed asset(s), %d would %s%s and %d would be skipped. Apply revalidates the complete %d-asset snapshot.',
            $expected,
            $would,
            $action,
            $action === 'move' ? ' to ' . $moveTo : '',
            $expected - $would,
            $totalCount,
        ));
        if ($action === 'move' && $would > 0) {
            $io->note('Target-folder permission, target-path availability, and folder creation are revalidated when the move runs.');
        }
    }

    /**
     * @param list<int> $ids
     * @return array{succeeded: int, failed: int, processed: int, errors: array<int, string>, observerWarnings: list<string>}
     */
    protected function processIds(OutputInterface $output, array $ids, string $action, string $moveTo, int $batchSize, ?array $expectedFingerprints = null): array
    {
        $expectedFingerprints ??= $this->fingerprints->fingerprintMap($ids);
        $progressBar = new ProgressBar($output, count($ids));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        $succeeded = 0;
        $failed = 0;
        $errors = [];
        $observerWarnings = [];

        foreach (array_chunk($ids, $batchSize) as $batch) {
            if ($action === 'delete') {
                $batchResult = $this->unusedAssetFinder->deleteAssets($batch, $expectedFingerprints);
                $succeeded += $batchResult['deleted'];
            } else {
                $batchResult = $this->unusedAssetFinder->moveAssets($batch, $moveTo, $expectedFingerprints);
                $succeeded += $batchResult['moved'];
            }

            $failed += $batchResult['failed'];
            $errors += $batchResult['errors'];
            array_push($observerWarnings, ...($batchResult['observerWarnings'] ?? []));
            $progressBar->advance(count($batch));
        }

        $progressBar->finish();

        return ['succeeded' => $succeeded, 'failed' => $failed, 'processed' => count($ids), 'errors' => $errors, 'observerWarnings' => $observerWarnings];
    }

    private function runForIds(SymfonyStyle $io, OutputInterface $output, string $byIds, string $action, string $moveTo, int $batchSize, bool $apply, mixed $planToken): int
    {
        $ids = $this->validatedCsvIds($io, $byIds, '--by-ids');
        if ($ids === null) {
            return Command::INVALID;
        }

        $io->title('Asset Pilot — Unused Asset Cleanup (by ids)');
        $io->text(sprintf('Targeting <info>%d</info> asset id(s); each is re-verified as unused and permission-checked before %s.', count($ids), $action));

        sort($ids, SORT_NUMERIC);
        $request = ['action' => $action, 'assetIds' => $ids, 'mode' => 'asset_ids', 'moveTo' => $moveTo];
        if (!$apply) {
            $io->note('DRY RUN — no changes will be made.');
            $would = [];
            $skip = [];
            foreach ($ids as $id) {
                $reason = $this->previewMutation($id, $action);
                if ($reason === null) {
                    $would[] = $id;
                } else {
                    $skip[$id] = $reason;
                }
            }
            if ($would !== []) {
                $io->text(sprintf('Would %s: %s', $action, implode(', ', $would)));
            } else {
                $io->text(sprintf('No assets would be %s.', $action === 'move' ? 'moved' : 'deleted'));
            }
            foreach ($skip as $id => $reason) {
                $io->text(sprintf('Would skip %d: %s', $id, $reason));
            }
            if ($action === 'move' && $would !== []) {
                $io->note('Target-folder permission, target-path availability, and folder creation are revalidated when the move runs.');
            }
            try {
                $this->renderPlanToken($io, $this->plan($ids, $request)['plan']);
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        try {
            $review = $this->plan($ids, $request);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $rejection = $this->claimPlan($io, (string) $planToken, $review['plan']);
        if ($rejection !== null) {
            return $rejection;
        }
        $result = $this->processIds($output, $ids, $action, $moveTo, $batchSize, $review['fingerprints']);
        $io->newLine(2);
        $this->renderSummary($io, $action, $result);

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<int>            $ids
     * @param array<string, mixed> $request
     * @return array{plan: ApplyPlan, fingerprints: array<string, string>}
     */
    private function plan(array $ids, array $request): array
    {
        $fingerprints = $this->fingerprints->fingerprintMap($ids);
        $targets = [];
        foreach ($ids as $id) {
            $key = 'asset:' . $id;
            $fingerprint = $fingerprints[$key] ?? null;
            if (!is_string($fingerprint) || $fingerprint === '') {
                throw new \RuntimeException(sprintf('Asset %d could not be fingerprinted; no action was taken.', $id));
            }
            $targets[] = new ApplyPlanTarget($key, $fingerprint);
        }

        return [
            'plan' => new ApplyPlan('cleanup-unused', ActorContext::system(), $request, [], $targets),
            'fingerprints' => $fingerprints,
        ];
    }

    private function renderPlanToken(SymfonyStyle $io, ApplyPlan $plan): void
    {
        $io->writeln('<info>Plan token:</info> ' . $this->applyPlans->issue($plan));
        $io->note('Apply this exact reviewed selection with --apply --plan-token=... before the token expires.');
    }

    private function claimPlan(SymfonyStyle $io, string $token, ApplyPlan $plan): ?int
    {
        $status = $this->applyPlans->claim($token, $plan);
        if ($status === ApplyPlanStatus::Claimed) {
            return null;
        }

        if ($status === ApplyPlanStatus::Malformed) {
            $io->error('The plan token is malformed. Run the preview again.');

            return Command::INVALID;
        }

        $io->error('The reviewed plan is stale or was already used. Run the preview again.');

        return Command::FAILURE;
    }

    private function previewMutation(int $id, string $action): ?string
    {
        if (!$this->loopGuard->acquireAsset($id)) {
            return 'Asset is being processed by another job';
        }

        try {
            return $this->unusedAssetFinder->previewMutation($id, $action);
        } finally {
            $this->loopGuard->releaseAsset($id);
        }
    }

    /**
     * @param array{succeeded: int, failed: int, processed: int, errors: array<int, string>, observerWarnings: list<string>} $result
     */
    protected function renderSummary(SymfonyStyle $io, string $action, array $result): void
    {
        $actionPast = $action === 'delete' ? 'deleted' : 'moved';
        $message = sprintf(
            'Cleanup complete: %d %s, %d failed out of %d total.',
            $result['succeeded'],
            $actionPast,
            $result['failed'],
            $result['processed'],
        );
        if ($result['failed'] > 0) {
            $io->error($message);
        } else {
            $io->success($message);
        }

        foreach (array_unique($result['observerWarnings']) as $warning) {
            $io->warning($warning);
        }

        if (empty($result['errors'])) {
            return;
        }

        $io->warning(sprintf('%d error(s):', count($result['errors'])));
        foreach (array_slice($result['errors'], 0, 20, true) as $id => $error) {
            $io->text(sprintf('  Asset %d: %s', $id, $error));
        }
        if (count($result['errors']) > 20) {
            $io->text(sprintf('  ... and %d more errors.', count($result['errors']) - 20));
        }
    }

    /**
     * Page through the unused listing once and collect the ids, before any mutation shifts the pages.
     *
     * @param array<string, mixed> $filters
     * @return list<int>
     */
    protected function collectUnusedIds(array $filters, int $batchSize, int $totalCount): array
    {
        $ids = [];
        $page = 1;

        do {
            $result = $this->unusedAssetFinder->findUnused($filters, $page, $batchSize);
            if (($result['total'] ?? null) !== $totalCount) {
                throw new \RuntimeException('The unused-asset selection changed while its snapshot was being collected; no action was taken.');
            }
            $items = $result['items'];
            foreach ($items as $item) {
                $ids[] = (int) $item['id'];
            }
            ++$page;
        } while (count($items) === $batchSize && count($ids) < $totalCount);

        if (count($ids) !== $totalCount || count(array_unique($ids)) !== $totalCount) {
            throw new \RuntimeException('The unused-asset snapshot could not be read completely; no action was taken.');
        }

        return $ids;
    }

    private function buildFilters(InputInterface $input): array
    {
        $filters = [];

        $before = $input->getOption('before');
        if ($before !== null) {
            $filters['before'] = $before;
        }

        $after = $input->getOption('after');
        if ($after !== null) {
            $filters['after'] = $after;
        }

        $type = $input->getOption('type');
        if ($type !== null) {
            $filters['type'] = $type;
        }

        $extension = $input->getOption('extension');
        if ($extension !== null) {
            $filters['extension'] = $extension;
        }

        $folder = $input->getOption('folder');
        if ($folder !== null) {
            $filters['folder'] = $folder;
        }

        return $filters;
    }
}
