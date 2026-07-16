<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\BoundedIntegerOption;
use Oronts\AssetPilotBundle\Command\Support\ReviewedSelectionConsolePresenter;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\ReviewedSelectionException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ReviewedSelectionResult;
use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:organize',
    description: 'Organize assets for data objects based on configured rules',
)]
class OrganizeCommand extends Command
{
    public function __construct(
        protected readonly ReviewedObjectOperationServiceInterface $reviewedOperations,
        protected readonly ReviewedSelectionConsolePresenter $presenter,
        protected readonly RuleEngineInterface $ruleEngine,
        protected readonly AssetFieldExtractorInterface $fieldExtractor,
        protected readonly NamingStrategyInterface $namingStrategy,
        protected readonly int $defaultBatchSize = 50,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'DataObject class name to organize')
            ->addOption('object-id', 'o', InputOption::VALUE_REQUIRED, 'Specific object ID to organize')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the reviewed organization; without this option the command only previews')
            ->addOption('plan-token', null, InputOption::VALUE_REQUIRED, 'Signed, single-use token returned by the matching preview')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Queue the reviewed organization via Messenger instead of running inline')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Batch size for bulk operations', (string) $this->defaultBatchSize);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $objectId = $input->getOption('object-id');
        $className = $this->className($input->getOption('class'));
        $apply = (bool) $input->getOption('apply');
        $planToken = $input->getOption('plan-token');

        if (!$this->hasValidPlanControl($io, $apply, $planToken)) {
            return Command::INVALID;
        }

        if ($objectId === null && $className === null) {
            $io->error('Either --class or --object-id must be provided.');
            return Command::FAILURE;
        }
        if ($objectId !== null && $className !== null) {
            $io->error('--class and --object-id cannot be used together.');

            return Command::FAILURE;
        }

        if ($objectId !== null) {
            $parsedObjectId = BoundedIntegerOption::parse($objectId, 1, PHP_INT_MAX);
            if ($parsedObjectId === null) {
                $io->error('--object-id must be a positive integer.');

                return Command::FAILURE;
            }

            return $this->runReviewed(
                $io,
                [$parsedObjectId],
                ['mode' => 'object_id', 'objectId' => $parsedObjectId],
                TriggerType::Manual,
                $apply,
                (bool) $input->getOption('async'),
                $planToken,
                $output->isVerbose(),
            );
        }

        $batchSize = BoundedIntegerOption::parse($input->getOption('batch-size'), 1, 1_000);
        if ($batchSize === null) {
            $io->error('--batch-size must be an integer between 1 and 1000.');

            return Command::FAILURE;
        }

        $total = $this->countObjectsForClass($className);
        if ($total === 0) {
            if ($apply) {
                $io->error('The reviewed class selection is no longer current. Preview again.');

                return Command::INVALID;
            }
            $io->warning("No objects found for class '{$className}'.");

            return Command::SUCCESS;
        }
        if ($total > 1_000) {
            $io->error(sprintf('The class contains %d objects, above the reviewed-operation maximum of 1000. Use a narrower operation selector.', $total));

            return Command::INVALID;
        }

        try {
            $ids = $this->collectObjectIds($className, $batchSize, $total);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return $this->runReviewed(
            $io,
            $ids,
            ['class' => $className, 'mode' => 'class'],
            TriggerType::BulkOperation,
            $apply,
            (bool) $input->getOption('async'),
            $planToken,
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

    /** @param list<int> $objectIds @param array<string, mixed> $selector */
    private function runReviewed(
        SymfonyStyle $io,
        array $objectIds,
        array $selector,
        TriggerType $trigger,
        bool $apply,
        bool $async,
        mixed $planToken,
        bool $verbose = false,
    ): int {
        try {
            $result = $this->reviewedOperations->execute(
                'organize',
                $objectIds,
                $selector,
                $trigger,
                !$apply,
                $async,
                $planToken,
                ActorContext::system(),
            );
        } catch (ReviewedSelectionException $e) {
            return $this->presenter->renderError($io, $e);
        }

        $this->presenter->render($io, $result, ['objects selected' => count($objectIds)]);
        if (!$apply && $verbose && count($objectIds) === 1) {
            $object = $this->loadObject($objectIds[0]);
            if ($object !== null) {
                $this->organizeSingleVerbose($io, $object);
            }
        }

        return $this->reviewedExit($io, $result, $apply, $async);
    }

    private function reviewedExit(SymfonyStyle $io, ReviewedSelectionResult $result, bool $apply, bool $async): int
    {
        if ($result->failed > 0) {
            $io->warning(sprintf('%d object(s) failed to organize; inspect the run and audit log.', $result->failed));

            return Command::FAILURE;
        }

        $io->success($apply ? ($async ? 'Organization queued.' : 'Organization complete.') : 'Preview complete; no assets were changed.');

        return Command::SUCCESS;
    }

    protected function loadObject(int $objectId): ?AbstractObject
    {
        return AbstractObject::getById($objectId);
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

    private function className(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    protected function countObjectsForClass(string $className): int
    {
        $listing = $this->classListing($className);

        return $listing->getTotalCount();
    }

    /** @return \Generator<int, list<int>> */
    protected function objectIdBatches(string $className, int $batchSize): \Generator
    {
        $lastId = 0;
        do {
            $listing = $this->classListing($className, $lastId);
            $listing->setOrderKey('id');
            $listing->setOrder('ASC');
            $listing->setLimit($batchSize);
            $ids = array_values($listing->loadIdList());
            if ($ids !== []) {
                yield $ids;
                $lastId = max($ids);
            }
        } while (count($ids) === $batchSize);
    }

    /** @return list<int> */
    private function collectObjectIds(string $className, int $batchSize, int $expectedCount): array
    {
        $ids = [];
        foreach ($this->objectIdBatches($className, $batchSize) as $batch) {
            array_push($ids, ...$batch);
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        if (count($ids) !== $expectedCount) {
            throw new \RuntimeException('The class selection changed while its snapshot was being collected. Run the preview again.');
        }

        return $ids;
    }

    private function classListing(string $className, ?int $afterId = null): DataObject\Listing
    {
        $listing = new DataObject\Listing();
        $listing->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_VARIANT]);
        $listing->setCondition(
            $afterId === null ? 'className = ?' : 'className = ? AND id > ?',
            $afterId === null ? [$className] : [$className, $afterId],
        );
        $listing->setUnpublished(false);

        return $listing;
    }

}
