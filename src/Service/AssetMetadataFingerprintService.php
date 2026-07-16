<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\Service as ElementService;
use Pimcore\Model\Element\Tag;
use Pimcore\Model\Property;

class AssetMetadataFingerprintService
{
    private const int VERSION = 1;

    /** @param list<int> $assetIds @return list<ApplyPlanTarget> */
    public function tagTargets(array $assetIds): array
    {
        return $this->targets($assetIds, fn (Asset $asset): array => [
            ...$this->assetState($asset),
            'tagIds' => $this->tagIds((int) $asset->getId()),
        ]);
    }

    /** @param list<int> $assetIds @return list<ApplyPlanTarget> */
    public function propertyTargets(array $assetIds, string $propertyName): array
    {
        return $this->targets($assetIds, fn (Asset $asset): array => [
            ...$this->assetState($asset),
            'property' => $this->propertyState($asset, $propertyName),
        ]);
    }

    /** @return array{version: int} */
    public function planConfig(): array
    {
        return ['version' => self::VERSION];
    }

    /** @param array<string, string> $expectedFingerprints */
    public function assertTagsUnchanged(Asset $asset, array $expectedFingerprints): void
    {
        $this->assertUnchanged($asset, $expectedFingerprints, [
            ...$this->assetState($asset),
            'tagIds' => $this->tagIds((int) $asset->getId()),
        ]);
    }

    /** @param array<string, string> $expectedFingerprints */
    public function assertPropertyUnchanged(Asset $asset, string $propertyName, array $expectedFingerprints): void
    {
        $this->assertUnchanged($asset, $expectedFingerprints, [
            ...$this->assetState($asset),
            'property' => $this->propertyState($asset, $propertyName),
        ]);
    }

    protected function loadAsset(int $assetId): ?Asset
    {
        return Asset::getById($assetId, ['force' => true]);
    }

    /** @return list<Tag> */
    protected function loadTags(int $assetId): array
    {
        return Tag::getTagsForElement('asset', $assetId);
    }

    /**
     * @param list<int> $assetIds
     * @param callable(Asset): array<string, mixed> $state
     * @return list<ApplyPlanTarget>
     */
    private function targets(array $assetIds, callable $state): array
    {
        sort($assetIds, SORT_NUMERIC);
        $targets = [];

        foreach ($assetIds as $assetId) {
            $asset = $this->loadAsset($assetId);
            $snapshot = $asset instanceof Asset
                ? $state($asset)
                : ['exists' => false, 'modifiedAt' => null, 'path' => null];
            $targets[] = new ApplyPlanTarget('asset:' . $assetId, $this->hash($snapshot));
        }

        return $targets;
    }

    /** @return array{exists: true, modifiedAt: int|null, path: string} */
    private function assetState(Asset $asset): array
    {
        return [
            'exists' => true,
            'modifiedAt' => $asset->getModificationDate(),
            'path' => $asset->getRealFullPath(),
        ];
    }

    /** @return list<int> */
    private function tagIds(int $assetId): array
    {
        $ids = array_values(array_filter(
            array_map(static fn (Tag $tag): int => (int) $tag->getId(), $this->loadTags($assetId)),
            static fn (int $id): bool => $id > 0,
        ));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function propertyState(Asset $asset, string $propertyName): array
    {
        $property = $asset->getProperty($propertyName, true);
        if (!$property instanceof Property) {
            return ['exists' => false];
        }

        return [
            'data' => $this->normalize($property->getData()),
            'exists' => true,
            'inheritable' => $property->getInheritable(),
            'inherited' => $property->getInherited(),
            'type' => $property->getType(),
        ];
    }

    /** @param array<string, string> $expectedFingerprints @param array<string, mixed> $snapshot */
    private function assertUnchanged(Asset $asset, array $expectedFingerprints, array $snapshot): void
    {
        $assetId = (int) $asset->getId();
        $key = 'asset:' . $assetId;
        if (!isset($expectedFingerprints[$key]) || !hash_equals($expectedFingerprints[$key], $this->hash($snapshot))) {
            throw new StaleApplyPlanException(sprintf(
                'Asset %d metadata changed after preview. Preview the operation again before applying.',
                $assetId,
            ));
        }
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof ElementInterface) {
            return [
                'elementType' => ElementService::getElementType($value),
                'id' => $value->getId(),
                'path' => $value->getRealFullPath(),
            ];
        }
        if ($value instanceof \DateTimeInterface) {
            return ['date' => $value->format('U.uP')];
        }
        if ($value instanceof \UnitEnum) {
            return ['enum' => $value::class, 'value' => $value instanceof \BackedEnum ? $value->value : $value->name];
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($this->normalize(...), $value);
        }
        if (is_object($value)) {
            return ['object' => $value::class, 'serialized' => base64_encode(serialize($value))];
        }

        return $value;
    }

    /** @param array<string, mixed> $snapshot */
    private function hash(array $snapshot): string
    {
        return hash('sha256', json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
