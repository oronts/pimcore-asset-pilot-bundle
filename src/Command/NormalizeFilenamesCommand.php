<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\BoundedIntegerOption;
use Oronts\AssetPilotBundle\Command\Support\UsesReviewedApplyPlan;
use Oronts\AssetPilotBundle\Command\Support\ValidatesCliBulkIds;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetMutationFingerprintService;
use Oronts\AssetPilotBundle\Service\NormalizeFilenamesServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:normalize-filenames',
    description: 'Rename assets whose filename is not a valid Pimcore key to the sanitized form (preview by default; --apply to rename)',
)]
class NormalizeFilenamesCommand extends Command
{
    use ValidatesCliBulkIds;
    use UsesReviewedApplyPlan;

    public function __construct(
        private readonly NormalizeFilenamesServiceInterface $normalizer,
        private readonly ApplyPlanServiceInterface $applyPlans,
        private readonly AssetMutationFingerprintService $fingerprints,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('by-ids', null, InputOption::VALUE_REQUIRED, 'Normalize only these specific asset ids (comma-separated)')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to this folder')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to an asset type')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to a file extension')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max assets to scan', '100')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Rename the exact reviewed selection')
            ->addOption('plan-token', null, InputOption::VALUE_REQUIRED, 'Signed, single-use token returned by the matching preview');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Normalize Filenames');
        $apply = (bool) $input->getOption('apply');
        $planToken = $input->getOption('plan-token');
        if (!$this->hasValidPlanControl($io, $apply, $planToken)) {
            return Command::INVALID;
        }

        $selection = $this->selection($input, $io);
        if ($selection === null) {
            return Command::INVALID;
        }

        $preview = $this->normalizer->normalize($selection['assetIds'], dryRun: true);
        if ($selection['assetIds'] === []) {
            if ($apply) {
                $io->error('The reviewed filename selection is no longer current. Preview again.');

                return Command::INVALID;
            }

            return $this->render($io, $preview, true);
        }

        $plan = $this->normalizationPlan($selection);
        if (!$apply) {
            $this->renderPlanToken($io, $this->applyPlans->issue($plan));

            return $this->render($io, $preview, true);
        }

        if (!$this->claimPlan($io, (string) $planToken, $plan)) {
            return Command::INVALID;
        }

        return $this->render($io, $this->normalizer->normalize(
            $selection['assetIds'],
            dryRun: false,
            expectedFingerprints: $plan->fingerprintMap(),
        ), false);
    }

    /**
     * @param array{renamed: int, skipped: int, failed: int, errors: array<int, string>, changes: list<array{id: int, from: string, to: string}>} $result
     */
    private function render(SymfonyStyle $io, array $result, bool $preview): int
    {
        if ($result['changes'] !== []) {
            $io->table(
                ['Asset', 'From', 'To'],
                array_map(static fn (array $c): array => [(string) $c['id'], $c['from'], $c['to']], $result['changes']),
            );
        }

        if ($result['failed'] > 0) {
            $io->warning(sprintf('%d asset(s) cannot be renamed:', $result['failed']));
            foreach (array_slice($result['errors'], 0, 20, true) as $id => $error) {
                $io->text(sprintf('  Asset %d: %s', $id, $error));
            }
        }

        if ($preview) {
            $io->note($result['changes'] === []
                ? 'No filenames need normalizing.'
                : sprintf('%d filename(s) would be normalized.', count($result['changes'])));

            return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $io->success(sprintf('Normalized %d filename(s) (%d skipped).', $result['renamed'], $result['skipped']));

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return array{assetIds: list<int>, selector: array<string, mixed>}|null */
    private function selection(InputInterface $input, SymfonyStyle $io): ?array
    {
        $byIds = $input->getOption('by-ids');
        if ($byIds !== null) {
            $assetIds = $this->validatedCsvIds($io, (string) $byIds, '--by-ids');
            if ($assetIds === null) {
                return null;
            }
            sort($assetIds, SORT_NUMERIC);

            return ['assetIds' => $assetIds, 'selector' => ['assetIds' => $assetIds, 'mode' => 'asset_ids']];
        }

        $filters = array_filter([
            'extension' => $input->getOption('extension'),
            'folder' => $input->getOption('folder'),
            'type' => $input->getOption('type'),
        ], static fn ($value): bool => $value !== null);
        $limit = BoundedIntegerOption::parse($input->getOption('limit'), 1, 1_000);
        if ($limit === null) {
            $io->error('--limit must be an integer between 1 and 1000.');

            return null;
        }
        $assetIds = $this->normalizer->findCandidates($filters, $limit);
        sort($assetIds, SORT_NUMERIC);

        return [
            'assetIds' => $assetIds,
            'selector' => ['filters' => $filters, 'limit' => $limit, 'mode' => 'scan'],
        ];
    }

    /**
     * @param array{assetIds: list<int>, selector: array<string, mixed>} $selection
     */
    private function normalizationPlan(array $selection): ApplyPlan
    {
        return new ApplyPlan(
            'normalize-filenames',
            ActorContext::system(),
            ['assetIds' => $selection['assetIds'], 'selector' => $selection['selector']],
            $this->fingerprints->planConfig(),
            $this->fingerprints->targets($selection['assetIds']),
        );
    }


}
