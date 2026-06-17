<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

#[AsCommand(
    name: 'asset-pilot:organize',
    description: 'Organize assets for data objects based on configured rules',
)]
class OrganizeCommand extends Command
{
    public function __construct(
        protected readonly AssetOrganizer $organizer,
        protected readonly MessageBusInterface $messageBus,
        protected readonly RuleEngine $ruleEngine,
        protected readonly AssetFieldExtractor $fieldExtractor,
        protected readonly NamingStrategyInterface $namingStrategy,
        protected readonly LoggerInterface $logger,
        protected readonly int $defaultBatchSize = 50,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'DataObject class name to organize')
            ->addOption('object-id', 'o', InputOption::VALUE_REQUIRED, 'Specific object ID to organize')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without actually moving assets')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch to messenger queue for async processing')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Batch size for bulk operations', (string) $this->defaultBatchSize);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $objectId = $input->getOption('object-id');
        $className = $input->getOption('class');
        $dryRun = $input->getOption('dry-run');
        $async = $input->getOption('async');

        if ($objectId === null && $className === null) {
            $io->error('Either --class or --object-id must be provided.');
            return Command::FAILURE;
        }

        $verbose = $output->isVerbose();

        // Single object mode
        if ($objectId !== null) {
            return $this->organizeSingle($io, (int) $objectId, $dryRun, $verbose);
        }

        // Bulk mode by class
        return $this->organizeBulk($io, $className, $dryRun, $async, (int) $input->getOption('batch-size'));
    }

    protected function organizeSingle(SymfonyStyle $io, int $objectId, bool $dryRun, bool $verbose = false): int
    {
        $object = AbstractObject::getById($objectId);
        if ($object === null) {
            $io->error("Object #{$objectId} not found.");
            return Command::FAILURE;
        }

        if ($dryRun) {
            if ($verbose) {
                return $this->organizeSingleVerbose($io, $object);
            }

            $operations = $this->organizer->dryRun($object);
            if (empty($operations)) {
                $io->success('No assets need organizing for this object.');
                return Command::SUCCESS;
            }

            $rows = [];
            foreach ($operations as $op) {
                $statusLabel = $op->status === OperationStatus::Skipped
                    ? '<fg=yellow>SKIP</> ' . ($op->errorMessage ?? '')
                    : '<fg=green>MOVE</>';
                $rows[] = [$op->assetId, $op->sourcePath, $op->targetPath, $op->ruleName, $statusLabel];
            }
            $io->table(['Asset ID', 'Source', 'Target', 'Rule', 'Status'], $rows);
            $pending = count(array_filter($operations, static fn ($op) => $op->status !== OperationStatus::Skipped));
            $skipped = count($operations) - $pending;
            $io->note("{$pending} asset(s) would be moved" . ($skipped > 0 ? ", {$skipped} skipped." : '.'));
            return Command::SUCCESS;
        }

        $results = $this->organizer->organize($object, TriggerType::Manual);
        $this->displayResults($io, $results);

        return Command::SUCCESS;
    }

    protected function organizeSingleVerbose(SymfonyStyle $io, AbstractObject $object): int
    {
        $fieldInfos = $this->fieldExtractor->extract($object);

        if (empty($fieldInfos)) {
            $io->success('No asset fields found on this object.');
            return Command::SUCCESS;
        }

        foreach ($fieldInfos as $fieldInfo) {
            $localeLabel = $fieldInfo->locale ?? 'none';
            $io->section(sprintf('Field: %s (locale: %s)', $fieldInfo->fieldName, $localeLabel));

            foreach ($fieldInfo->assets as $asset) {
                $io->text(sprintf('  Asset #%d: %s', $asset->getId(), $asset->getRealFullPath()));
                $io->newLine();

                $result = $this->ruleEngine->explain($object, $asset, $fieldInfo->fieldName, $fieldInfo->locale);
                $evaluations = $result['evaluations'];
                $matches = $result['matches'];

                $rows = [];
                foreach ($evaluations as $eval) {
                    $resultLabel = $eval->matched ? '<fg=green>MATCHED</>' : '<fg=yellow>SKIPPED</>';
                    $rows[] = [$eval->ruleName, $resultLabel, $eval->describe()];
                }

                $io->table(['Rule', 'Result', 'Detail'], $rows);

                if (!empty($matches)) {
                    $match = $matches[0];
                    $targetFilename = $this->namingStrategy->generateName($match->asset, $match->resolvedPath);
                    $fullPath = rtrim($match->resolvedPath, '/') . '/' . $targetFilename;
                    $io->text(sprintf('  Decision: Rule "%s" matched -> %s', $match->rule->name, $fullPath));
                } else {
                    $io->text('  Decision: No rules matched this asset.');
                }

                $io->newLine();
            }
        }

        return Command::SUCCESS;
    }

    protected function organizeBulk(SymfonyStyle $io, string $className, bool $dryRun, bool $async, int $batchSize): int
    {
        // Load all object IDs for the class
        $listing = new DataObject\Listing();
        $listing->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_VARIANT]);
        $listing->setCondition('className = ?', [$className]);
        $listing->setUnpublished(false);

        $objectIds = [];
        foreach ($listing as $object) {
            $objectIds[] = $object->getId();
        }

        if (empty($objectIds)) {
            $io->warning("No objects found for class '{$className}'.");
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d %s object(s) to process.', count($objectIds), $className));

        if ($dryRun) {
            $io->note('Dry run - showing preview for first 5 objects:');
            $previewIds = array_slice($objectIds, 0, 5);
            foreach ($previewIds as $id) {
                $obj = AbstractObject::getById($id);
                if ($obj === null) {
                    continue;
                }
                $ops = $this->organizer->dryRun($obj);
                foreach ($ops as $op) {
                    $io->writeln("  Asset #{$op->assetId}: {$op->sourcePath} -> {$op->targetPath} ({$op->ruleName})");
                }
            }
            return Command::SUCCESS;
        }

        if ($async) {
            // Dispatch in batches
            $batches = array_chunk($objectIds, max(1, $batchSize));
            foreach ($batches as $batch) {
                $key = 'asset_pilot_bulk_' . md5(implode(',', $batch));
                $this->messageBus->dispatch(Envelope::wrap(
                    new BulkOrganizeMessage(
                        objectIds: $batch,
                        triggerType: TriggerType::BulkOperation,
                    ),
                    [new DeduplicateStamp($key, 60.0)],
                ));
            }
            $io->success(sprintf('Dispatched %d batch(es) to messenger queue.', count($batches)));
            return Command::SUCCESS;
        }

        // Synchronous bulk
        $progressBar = $io->createProgressBar(count($objectIds));
        $results = $this->organizer->organizeBulk(
            $objectIds,
            TriggerType::BulkOperation,
            static function (int $current, int $total) use ($progressBar): void {
                $progressBar->setProgress($current);
            },
        );
        $progressBar->finish();
        $io->newLine(2);

        $this->displayResults($io, $results);

        return Command::SUCCESS;
    }

    protected function displayResults(SymfonyStyle $io, array $results): void
    {
        $moved = count(array_filter($results, static fn ($r) => $r->status === OperationStatus::Completed));
        $skipped = count(array_filter($results, static fn ($r) => $r->status === OperationStatus::Skipped));
        $failed = count(array_filter($results, static fn ($r) => $r->status === OperationStatus::Failed));

        $io->table(['Status', 'Count'], [
            ['Moved', $moved],
            ['Skipped', $skipped],
            ['Failed', $failed],
            ['Total', count($results)],
        ]);

        if ($failed > 0) {
            $io->warning("{$failed} operation(s) failed. Check the audit log for details.");
        } else {
            $io->success("Organization complete: {$moved} moved, {$skipped} skipped.");
        }
    }
}
