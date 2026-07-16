<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Service\Query\PimcoreSchema;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Document;
use Pimcore\Model\Element\AbstractElement;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

class DependencyUsageScanner implements DependencyUsageScannerInterface, ResetInterface
{
    /** @var array<int, true>|null */
    private ?array $referencedAssetIds = null;

    private bool $scanFailed = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly int $maxSources = 50000,
    ) {}

    public function reset(): void
    {
        $this->referencedAssetIds = null;
        $this->scanFailed = false;
    }

    public function isReferenced(Asset $asset): bool
    {
        $this->buildIndex();

        return $this->scanFailed || isset($this->referencedAssetIds[(int) $asset->getId()]);
    }

    private function buildIndex(): void
    {
        if ($this->referencedAssetIds !== null || $this->scanFailed) {
            return;
        }

        $this->referencedAssetIds = [];
        $sources = 0;
        try {
            foreach ($this->sourceElements() as $source) {
                if (++$sources > $this->maxSources) {
                    throw new \RuntimeException(sprintf('Live dependency scan exceeded its %d-source safety budget.', $this->maxSources));
                }

                foreach ($source->resolveDependencies() as $dependency) {
                    if (($dependency['type'] ?? null) === PimcoreSchema::ELEMENT_TYPE_ASSET) {
                        $this->referencedAssetIds[(int) $dependency['id']] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->scanFailed = true;
            $this->referencedAssetIds = [];
            $this->logger->error('Asset Pilot: live dependency verification failed; destructive actions remain blocked.', [
                'exception' => $e,
            ]);
        }
    }

    /** @return \Generator<int, AbstractElement> */
    protected function sourceElements(): \Generator
    {
        $hideObjects = AbstractObject::getHideUnpublished();
        $inheritValues = AbstractObject::getGetInheritedValues();
        $hideDocuments = Document::doHideUnpublished();
        AbstractObject::setHideUnpublished(false);
        AbstractObject::setGetInheritedValues(false);
        Document::setHideUnpublished(false);

        try {
            foreach ($this->sourceIds(PimcoreSchema::TABLE_OBJECTS) as $id) {
                $source = AbstractObject::getById($id, ['force' => true]);
                if ($source !== null) {
                    yield $source;
                }
            }
            foreach ($this->sourceIds(PimcoreSchema::TABLE_DOCUMENTS) as $id) {
                $source = Document::getById($id, ['force' => true]);
                if ($source !== null) {
                    yield $source;
                }
            }
            foreach ($this->sourceIds(PimcoreSchema::TABLE_ASSETS) as $id) {
                $source = Asset::getById($id, ['force' => true]);
                if ($source !== null) {
                    yield $source;
                }
            }
        } finally {
            AbstractObject::setHideUnpublished($hideObjects);
            AbstractObject::setGetInheritedValues($inheritValues);
            Document::setHideUnpublished($hideDocuments);
        }
    }

    /** @return \Generator<int, int> */
    private function sourceIds(string $table): \Generator
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id')
            ->from($table)
            ->orderBy('id', 'ASC')
            ->executeQuery();

        foreach ($rows->iterateColumn() as $id) {
            yield (int) $id;
        }
    }
}
