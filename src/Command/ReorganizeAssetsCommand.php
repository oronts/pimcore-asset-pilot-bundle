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
use Oronts\AssetPilotBundle\Service\AssetReorganizerInterface;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
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
    use ValidatesCliBulkIds;

    public function __construct(
        private readonly AssetReorganizerInterface $reorganizer,
        private readonly ReviewedObjectOperationServiceInterface $reviewedOperations,
        private readonly ReviewedSelectionConsolePresenter $presenter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('by-ids', null, InputOption::VALUE_REQUIRED, 'Re-organize the owners of these specific asset ids (comma-separated), instead of scanning a folder')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Source asset folder to scan (e.g. /Staging)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max assets to scan', '100')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Queue the reviewed owners via Messenger instead of running inline')
            ->addOption('plan-token', null, InputOption::VALUE_REQUIRED, 'Signed token returned by the matching preview')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the reorganization; without this option the command only previews');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Reorganize Assets');

        $async = (bool) $input->getOption('async');
        $apply = (bool) $input->getOption('apply');
        $planToken = $input->getOption('plan-token');
        if (!$this->hasValidPlanControl($io, $apply, $planToken)) {
            return Command::INVALID;
        }

        $selection = $this->selection($input, $io);
        if (is_int($selection)) {
            return $selection;
        }

        if ($selection['assets']['truncated'] ?? false) {
            $io->warning('The folder scan stopped at the candidate budget; more assets may exist beyond this selection. Re-run after this batch to continue.');
        }

        $result = $this->runReviewedSelection($io, $selection, $async, $apply, $planToken);
        if (is_int($result)) {
            return $result;
        }

        return $this->report(
            $io,
            $result,
            $selection['assets']['assetCount'],
            count($selection['assets']['objectIds']),
            $async,
            $apply,
        );
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

    /**
     * @return array{
     *     assets: array{assetCount: int, objectIds: list<int>, truncated: bool},
     *     selector: array<string, mixed>
     * }|int
     */
    private function selection(InputInterface $input, SymfonyStyle $io): array|int
    {
        $byIds = $input->getOption('by-ids');
        $folder = $input->getOption('folder');
        if ($byIds !== null && $folder !== null && $folder !== '') {
            $io->error('Provide either --by-ids or --folder, not both.');

            return Command::INVALID;
        }

        if ($byIds !== null) {
            $ids = $this->validatedCsvIds($io, (string) $byIds, '--by-ids');
            if ($ids === null) {
                return Command::INVALID;
            }
            sort($ids, SORT_NUMERIC);

            return [
                'assets' => $this->reorganizer->selectAssets($ids),
                'selector' => ['assetIds' => $ids, 'mode' => 'asset_ids'],
            ];
        }

        if ($folder !== null && $folder !== '') {
            $limit = BoundedIntegerOption::parse($input->getOption('limit'), 1, 1_000);
            if ($limit === null) {
                $io->error('--limit must be an integer between 1 and 1000.');

                return Command::INVALID;
            }

            return [
                'assets' => $this->reorganizer->selectFolder((string) $folder, $limit),
                'selector' => ['folder' => (string) $folder, 'limit' => $limit, 'mode' => 'folder'],
            ];
        }

        $io->error('Provide either --by-ids or --folder.');

        return Command::INVALID;
    }

    /**
     * @param array{
     *     assets: array{assetCount: int, objectIds: list<int>, truncated: bool},
     *     selector: array<string, mixed>
     * } $selection
     */
    private function runReviewedSelection(
        SymfonyStyle $io,
        array $selection,
        bool $async,
        bool $apply,
        mixed $planToken,
    ): ReviewedSelectionResult|int {
        try {
            return $this->reviewedOperations->execute(
                OperationRunKind::Reorganize,
                $selection['assets']['objectIds'],
                $selection['selector'],
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

    private function report(
        SymfonyStyle $io,
        ReviewedSelectionResult $result,
        int $assetsScanned,
        int $ownerObjects,
        bool $async,
        bool $apply,
    ): int {
        $this->presenter->render($io, $result, [
            'assets scanned' => $assetsScanned,
            'owner objects' => $ownerObjects,
        ]);

        if ($result->failed > 0) {
            $io->warning(sprintf('%d owner object(s) failed to re-organize; see the log.', $result->failed));

            return Command::FAILURE;
        }

        $io->success($apply ? ($async ? 'Reorganize queued.' : 'Reorganize complete.') : 'Preview complete; no assets were changed.');

        return Command::SUCCESS;
    }
}
