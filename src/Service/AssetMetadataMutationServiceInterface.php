<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;

interface AssetMetadataMutationServiceInterface
{
    /** @param list<int> $assetIds @param list<int> $tagIds */
    public function tagPlan(ActorContext $actor, array $assetIds, array $tagIds, bool $replace): ApplyPlan;

    /** @param list<int> $assetIds */
    public function propertyPlan(ActorContext $actor, array $assetIds, string $name, string $type, string|bool $value): ApplyPlan;

    /**
     * @param list<int> $assetIds
     * @param list<int> $tagIds
     * @param array<string, string> $expectedFingerprints
     *
     * @return array{tagged: int, failed: int, errors: array<int|string, string>, observerWarnings: list<string>}
     */
    public function applyTags(array $assetIds, array $tagIds, bool $replace, array $expectedFingerprints): array;

    /**
     * @param list<int> $assetIds
     * @param array<string, string> $expectedFingerprints
     * @return array{updated: int, failed: int, errors: array<int|string, string>, observerWarnings: list<string>}
     */
    public function applyProperty(array $assetIds, string $name, string $type, string|bool $value, array $expectedFingerprints): array;
}
