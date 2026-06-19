<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Merge\RepointReport;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;

/**
 * Re-points references from a duplicate copy onto the canonical asset, so the copy can be safely
 * disposed. It rewrites the reference surfaces it can do precisely — typed asset relation fields
 * (image, many-to-one, many-to-many) and hard-coded asset paths/ids embedded in WYSIWYG fields — and
 * then asks Pimcore to recompute the object's dependencies: if the object STILL references the copy
 * (the reference lives in a brick/block/fieldcollection or an advanced/metadata relation this version
 * does not rewrite), that object is reported as blocked and the copy is left for review. A copy is
 * therefore never disposed while any reference to it remains. Each owner save runs inside the
 * LoopGuard processing window so the resulting DataObject save event does not re-enter the organize
 * pipeline.
 */
class DuplicateReferenceRepointer
{
    private const int PAGE_SIZE = 100;

    /** Relation field types this version rewrites precisely; everything else is caught by the recheck. */
    private const array HANDLED_RELATION_TYPES = ['image', 'manyToOneRelation', 'manyToManyRelation'];

    public function __construct(
        protected readonly LoopGuard $loopGuard,
        protected readonly LoggerInterface $logger,
    ) {}

    public function repoint(int $fromAssetId, int $toAssetId, bool $dryRun = false): RepointReport
    {
        $from = $this->loadAsset($fromAssetId);
        $to = $this->loadAsset($toAssetId);
        if ($from === null || $to === null) {
            return new RepointReport($fromAssetId, $toAssetId, 0, ['the source or canonical asset no longer exists']);
        }

        // Snapshot every referrer BEFORE mutating anything: saving a repointed object removes it from
        // the copy's reverse-dependency list, so paging with a moving offset against a shrinking list
        // would skip referrers and could wrongly report the copy as fully repointed.
        $referrers = $this->collectReferrers($fromAssetId);

        $blocked = [];
        $repointed = 0;

        foreach ($referrers as $row) {
            $type = (string) ($row['type'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($type !== 'object') {
                $blocked[] = sprintf('%s %d references the copy and is not rewritten in this version', $type !== '' ? $type : 'element', $id);
                continue;
            }

            try {
                [$changed, $objectBlocked] = $this->repointObject($id, $from, $to, $dryRun);
            } catch (\Throwable $e) {
                $this->logger->error('Asset Pilot: failed to repoint references on object {id}: {error}', [
                    'id' => $id,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $blocked[] = sprintf('object %d could not be repointed: %s', $id, $e->getMessage());
                continue;
            }

            if ($changed) {
                ++$repointed;
            }
            if ($objectBlocked !== null) {
                $blocked[] = $objectBlocked;
            }
        }

        return new RepointReport($fromAssetId, $toAssetId, $repointed, $blocked);
    }

    /**
     * Page through the copy's reverse dependencies read-only and return the de-duplicated set, so the
     * subsequent (mutating) repoint pass works from a stable snapshot.
     *
     * @return list<array{id: int|string, type: string}>
     */
    private function collectReferrers(int $fromAssetId): array
    {
        $rows = [];
        $seen = [];
        $offset = 0;

        while (true) {
            $page = $this->requiredBy($fromAssetId, $offset, self::PAGE_SIZE);
            if ($page === []) {
                break;
            }
            foreach ($page as $row) {
                $key = ($row['type'] ?? '') . ':' . ($row['id'] ?? 0);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = $row;
                }
            }
            if (count($page) < self::PAGE_SIZE) {
                break;
            }
            $offset += self::PAGE_SIZE;
        }

        return $rows;
    }

    /**
     * @return array{0: bool, 1: ?string} [whether anything was rewritten, a blocked reason or null]
     */
    private function repointObject(int $objectId, Asset $from, Asset $to, bool $dryRun): array
    {
        $object = $this->loadObject($objectId);
        if ($object === null) {
            // Stale reverse-dependency row: the object is gone, nothing to rewrite.
            return [false, null];
        }

        $fromId = (int) $from->getId();
        $changed = false;

        foreach ($this->relationFieldDefs($object) as [$name, $type]) {
            [$fieldChanged, $newValue] = $this->replaceAssetReference($type, $this->fieldValue($object, $name), $fromId, $to);
            if ($fieldChanged) {
                $this->setFieldValue($object, $name, $newValue);
                $changed = true;
            }
        }

        $fromPath = $from->getRealFullPath();
        $toPath = $to->getRealFullPath();
        $toId = (int) $to->getId();
        foreach ($this->wysiwygFieldNames($object) as $name) {
            [$fieldChanged, $newHtml] = $this->replacePathInHtml((string) $this->fieldValue($object, $name), $fromPath, $toPath, $fromId, $toId);
            if ($fieldChanged) {
                $this->setFieldValue($object, $name, $newHtml);
                $changed = true;
            }
        }

        if ($dryRun) {
            // Preview: nothing is saved, so Pimcore cannot recompute dependencies and we cannot confirm
            // a brick/block/fieldcollection or advanced relation does not also reference the copy.
            // Report only what would change; whether the copy is disposable is decided on --apply.
            return [$changed, null];
        }

        if ($changed) {
            $this->loopGuard->markObjectProcessing($objectId);
            try {
                $this->saveObject($object);
            } finally {
                $this->loopGuard->unmarkObjectProcessing($objectId);
            }
        }

        if ($this->objectStillReferences($objectId, $fromId)) {
            return [$changed, sprintf('object %d still references the copy after repoint (nested/advanced field not rewritten in this version)', $objectId)];
        }

        return [$changed, null];
    }

    /**
     * Pure: produce the new field value with the copy swapped for the canonical asset. Returns
     * [changed, newValue]. Only asset-bearing relation values are rewritten; unhandled types are
     * returned untouched (the post-save dependency recheck blocks the copy if they still point at it).
     *
     * @return array{0: bool, 1: mixed}
     */
    protected function replaceAssetReference(string $type, mixed $value, int $fromId, Asset $to): array
    {
        switch ($type) {
            case 'image':
            case 'manyToOneRelation':
                if ($value instanceof Asset && (int) $value->getId() === $fromId) {
                    return [true, $to];
                }

                return [false, $value];

            case 'manyToManyRelation':
                if (!is_array($value)) {
                    return [false, $value];
                }
                $toId = (int) $to->getId();
                $changed = false;
                $result = [];
                foreach ($value as $element) {
                    if ($element instanceof Asset && (int) $element->getId() === $fromId) {
                        $element = $to;
                        $changed = true;
                    }
                    if ($element instanceof Asset && (int) $element->getId() === $toId && $this->containsAsset($result, $toId)) {
                        // The canonical asset is already in the list: collapse the duplicate.
                        $changed = true;
                        continue;
                    }
                    $result[] = $element;
                }

                return [$changed, $result];

            default:
                return [false, $value];
        }
    }

    /**
     * Pure: rewrite a copy's reference to the canonical asset's inside markup. The path is only
     * replaced where it appears as a quoted attribute value (src="…"/href="…"), so a path that
     * happens to occur as plain text or inside an unrelated attribute is left alone.
     *
     * @return array{0: bool, 1: string}
     */
    protected function replacePathInHtml(string $html, string $fromPath, string $toPath, int $fromId, int $toId): array
    {
        $new = str_replace(
            ['"' . $fromPath . '"', "'" . $fromPath . "'", 'pimcore_id="' . $fromId . '"'],
            ['"' . $toPath . '"', "'" . $toPath . "'", 'pimcore_id="' . $toId . '"'],
            $html,
        );

        return [$new !== $html, $new];
    }

    /**
     * @param list<mixed> $elements
     */
    private function containsAsset(array $elements, int $assetId): bool
    {
        foreach ($elements as $element) {
            if ($element instanceof Asset && (int) $element->getId() === $assetId) {
                return true;
            }
        }

        return false;
    }

    protected function loadAsset(int $id): ?Asset
    {
        return Asset::getById($id);
    }

    /**
     * @return list<array{id: int|string, type: string}>
     */
    protected function requiredBy(int $assetId, int $offset, int $limit): array
    {
        $asset = Asset::getById($assetId);
        if ($asset === null) {
            return [];
        }

        return $asset->getDependencies()->getRequiredBy($offset, $limit);
    }

    protected function loadObject(int $id): ?Concrete
    {
        return Concrete::getById($id);
    }

    /**
     * The object's top-level asset-relation fields this version can rewrite: [name, fieldType].
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function relationFieldDefs(Concrete $object): array
    {
        $fields = [];
        foreach ($object->getClass()->getFieldDefinitions() as $fieldDef) {
            if (in_array($fieldDef->getFieldType(), self::HANDLED_RELATION_TYPES, true)) {
                $fields[] = [$fieldDef->getName(), $fieldDef->getFieldType()];
            }
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    protected function wysiwygFieldNames(Concrete $object): array
    {
        $names = [];
        foreach ($object->getClass()->getFieldDefinitions() as $fieldDef) {
            if ($fieldDef->getFieldType() === 'wysiwyg') {
                $names[] = $fieldDef->getName();
            }
        }

        return $names;
    }

    protected function fieldValue(Concrete $object, string $name): mixed
    {
        $getter = 'get' . ucfirst($name);

        return method_exists($object, $getter) ? $object->{$getter}() : null;
    }

    protected function setFieldValue(Concrete $object, string $name, mixed $value): void
    {
        $setter = 'set' . ucfirst($name);
        if (method_exists($object, $setter)) {
            $object->{$setter}($value);
        }
    }

    protected function saveObject(Concrete $object): void
    {
        $object->save();
    }

    /**
     * Reloads the object and asks Pimcore (which tracks nested references too) whether it still
     * requires the copy after the rewrite.
     */
    protected function objectStillReferences(int $objectId, int $fromAssetId): bool
    {
        $object = Concrete::getById($objectId, ['force' => true]);
        if ($object === null) {
            return false;
        }

        foreach ($object->getDependencies()->getRequires() as $row) {
            if (($row['type'] ?? null) === 'asset' && (int) ($row['id'] ?? 0) === $fromAssetId) {
                return true;
            }
        }

        return false;
    }
}
