<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

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
    description: 'Find and clean up unused assets (not referenced by any object). Designed for cronjob use.',
)]
class CleanupUnusedCommand extends Command
{
    public function __construct(
        private readonly UnusedAssetFinderInterface $unusedAssetFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Assets modified before this date (e.g. "2024-01-01", "-90 days", "-6 months")')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Assets modified after this date')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by asset type, comma-separated (e.g. "image,document")')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Filter by file extension, comma-separated (e.g. "pdf,png,jpg")')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Limit to assets in this folder path (e.g. "/uploads/temp")')
            ->addOption('action', null, InputOption::VALUE_REQUIRED, 'Action to perform: "delete" or "move" (default: delete)', 'delete')
            ->addOption('move-to', null, InputOption::VALUE_REQUIRED, 'Target folder when action=move (e.g. "/archive/unused")')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only — do not delete or move anything')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Process assets in batches of this size', '100')
            ->setHelp(<<<'HELP'
Find and clean up assets that are not referenced by any Pimcore data object or document.

<info>Examples:</info>

  Preview unused image assets older than 90 days:
    <comment>bin/console asset-pilot:cleanup-unused --dry-run --before="-90 days" --type=image</comment>

  Delete unused PDFs older than 6 months:
    <comment>bin/console asset-pilot:cleanup-unused --before="-6 months" --extension=pdf</comment>

  Move unused assets from /uploads/temp to /archive:
    <comment>bin/console asset-pilot:cleanup-unused --folder=/uploads/temp --action=move --move-to=/archive/unused</comment>

  Cronjob: clean up unused images older than 90 days (nightly):
    <comment>0 2 * * * bin/console asset-pilot:cleanup-unused --before="-90 days" --type=image --batch-size=200</comment>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filters = $this->buildFilters($input);
        $action = $input->getOption('action');
        $batchSize = (int) $input->getOption('batch-size');
        $moveTo = (string) $input->getOption('move-to');

        if ($action === 'move' && empty($moveTo)) {
            $io->error('The --move-to option is required when action=move.');
            return Command::FAILURE;
        }

        if (!in_array($action, ['delete', 'move'], true)) {
            $io->error('Invalid action. Use "delete" or "move".');
            return Command::FAILURE;
        }

        $totalCount = $this->unusedAssetFinder->countUnused($filters);

        if ($totalCount === 0) {
            $io->success('No unused assets found matching the given criteria.');
            return Command::SUCCESS;
        }

        $io->title('Asset Pilot — Unused Asset Cleanup');
        $io->text(sprintf('Found <info>%d</info> unused asset(s) matching criteria.', $totalCount));

        if (!empty($filters)) {
            $io->text('Filters: ' . json_encode($filters, JSON_UNESCAPED_SLASHES));
        }

        if ($input->getOption('dry-run')) {
            $this->renderDryRunPreview($io, $output, $filters, $action, $moveTo, $totalCount);
            return Command::SUCCESS;
        }

        $io->text(sprintf('Action: <comment>%s</comment> | Batch size: %d', $action, $batchSize));
        $io->newLine();

        $result = $this->processBatches($output, $filters, $action, $moveTo, $batchSize, $totalCount);
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
        $table = new Table($output);
        $table->setHeaders(['ID', 'Path', 'Type', 'Size', 'Modified']);

        foreach ($result['items'] as $item) {
            $table->addRow([
                $item['id'],
                $item['full_path'],
                $item['type'],
                $this->formatBytes((int) $item['file_size']),
                $item['modified_at'] ?? '-',
            ]);
        }

        $table->render();

        if ($totalCount > 50) {
            $io->text(sprintf('... and %d more.', $totalCount - 50));
        }

        $io->newLine();
        $io->text(sprintf('Would %s %d asset(s)%s.', $action, $totalCount, $action === 'move' ? ' to ' . $moveTo : ''));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{succeeded: int, failed: int, processed: int, errors: array<int, string>}
     */
    protected function processBatches(OutputInterface $output, array $filters, string $action, string $moveTo, int $batchSize, int $totalCount): array
    {
        $progressBar = new ProgressBar($output, $totalCount);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        $succeeded = 0;
        $failed = 0;
        $errors = [];

        // Snapshot the candidate ids first: deleting shrinks the listing and moving leaves the asset
        // unused (just relocated), so re-querying mid-run would reprocess the same first page and skip
        // later assets. A fixed id list chunked into batches is correct for both actions.
        $ids = $this->collectUnusedIds($filters, $batchSize, $totalCount);

        foreach (array_chunk($ids, $batchSize) as $batch) {
            if ($action === 'delete') {
                $batchResult = $this->unusedAssetFinder->deleteAssets($batch);
                $succeeded += $batchResult['deleted'];
            } else {
                $batchResult = $this->unusedAssetFinder->moveAssets($batch, $moveTo);
                $succeeded += $batchResult['moved'];
            }

            $failed += $batchResult['failed'];
            $errors += $batchResult['errors'];
            $progressBar->advance(count($batch));
        }

        $progressBar->finish();

        return ['succeeded' => $succeeded, 'failed' => $failed, 'processed' => count($ids), 'errors' => $errors];
    }

    /**
     * @param array{succeeded: int, failed: int, processed: int, errors: array<int, string>} $result
     */
    protected function renderSummary(SymfonyStyle $io, string $action, array $result): void
    {
        $actionPast = $action === 'delete' ? 'deleted' : 'moved';
        $io->success(sprintf(
            'Cleanup complete: %d %s, %d failed out of %d total.',
            $result['succeeded'],
            $actionPast,
            $result['failed'],
            $result['processed'],
        ));

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
            $items = $this->unusedAssetFinder->findUnused($filters, $page, $batchSize)['items'];
            foreach ($items as $item) {
                $ids[] = (int) $item['id'];
            }
            ++$page;
        } while (count($items) === $batchSize && count($ids) < $totalCount);

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

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
