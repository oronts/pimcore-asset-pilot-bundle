<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\DriftItem;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Listing;
use Psr\Log\LoggerInterface;

/**
 * Organization-drift detection: after a rule change, previously-organized assets silently stay in
 * their old location. This compares each asset's actual path against where the current rules resolve
 * it (a dry run) and reports the mismatches. The per-class scan is paged and bounded so it never
 * turns into a full-catalog block; a whole-catalog sweep belongs in the CLI/async, not a request.
 */
class LocationDriftService
{
    public function __construct(
        protected readonly AssetOrganizer $organizer,
        protected readonly LoggerInterface $logger,
        protected readonly int $defaultLimit = 50,
    ) {}

    /**
     * @return list<DriftItem>
     */
    public function driftForObject(AbstractObject $object): array
    {
        $drift = [];
        foreach ($this->organizer->dryRun($object, TriggerType::Manual) as $operation) {
            // A Pending dry-run op is an asset the rules would move = it is not where they want it.
            // Skipped ops (already-at-target, locked, excluded) are not drift.
            if ($operation->status === OperationStatus::Pending) {
                $drift[] = new DriftItem($operation->assetId, $operation->sourcePath, $operation->targetPath, $operation->ruleName);
            }
        }

        return $drift;
    }

    /**
     * @return array{items: list<DriftItem>, objectsScanned: int, page: int, limit: int}
     */
    public function driftForClass(string $className, int $page = 1, ?int $limit = null): array
    {
        $limit = $limit ?? $this->defaultLimit;
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $ids = $this->listObjectIds($className, $offset, $limit);

        $items = [];
        $scanned = 0;
        foreach ($ids as $id) {
            $object = $this->loadObject((int) $id);
            if ($object === null) {
                continue;
            }
            ++$scanned;
            foreach ($this->driftForObject($object) as $driftItem) {
                $items[] = $driftItem;
            }
        }

        return ['items' => $items, 'objectsScanned' => $scanned, 'page' => $page, 'limit' => $limit];
    }

    /**
     * @return list<int>
     */
    protected function listObjectIds(string $className, int $offset, int $limit): array
    {
        $listing = new Listing();
        $listing->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_VARIANT]);
        $listing->setCondition('className = ?', [$className]);
        $listing->setOffset($offset);
        $listing->setLimit($limit);

        return array_map('intval', $listing->loadIdList());
    }

    protected function loadObject(int $id): ?AbstractObject
    {
        return AbstractObject::getById($id);
    }
}
