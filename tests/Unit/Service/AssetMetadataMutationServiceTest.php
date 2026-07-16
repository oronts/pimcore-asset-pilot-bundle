<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetMetadataFingerprintService;
use Oronts\AssetPilotBundle\Service\AssetMetadataMutationService;
use Oronts\AssetPilotBundle\Service\AssetPropertyService;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\Tag;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(AssetMetadataMutationService::class)]
final class AssetMetadataMutationServiceTest extends TestCase
{
    #[Test]
    public function tagPlanRejectsANonexistentRequestedTagBeforeFingerprinting(): void
    {
        $fingerprints = $this->createMock(AssetMetadataFingerprintService::class);
        $fingerprints->expects(self::never())->method('tagTargets');
        $service = new class (
            $this->createMock(LoopGuard::class),
            $this->createMock(ElementAuthorization::class),
            $this->createMock(AssetPropertyService::class),
            $fingerprints,
        ) extends AssetMetadataMutationService {
            protected function loadTag(int $tagId, bool $force = false): ?Tag
            {
                return null;
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag 99 does not exist.');
        $service->tagPlan(ActorContext::user(7), [5], [99], false);
    }

    #[Test]
    public function propertyApplyMutatesTheForceLoadedAssetWhileTheOuterLockIsHeld(): void
    {
        $loopGuard = new LoopGuard(
            new ArrayAdapter(),
            new LockFactory(new InMemoryStore()),
        );
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(5);
        $asset->expects(self::once())
            ->method('setProperty')
            ->with('source', 'text', 'catalog')
            ->willReturnSelf();
        $asset->expects(self::once())->method('save');

        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->with($asset, 'publish')->willReturn(true);
        $fingerprints = $this->createMock(AssetMetadataFingerprintService::class);
        $fingerprints->expects(self::once())
            ->method('assertPropertyUnchanged')
            ->with($asset, 'source', ['asset:5' => 'property-fingerprint']);
        $properties = new AssetPropertyService(
            $loopGuard,
            $authorization,
            new NullLogger(),
            new EventDispatcher(),
        );
        $service = new class (
            $loopGuard,
            $authorization,
            $properties,
            $fingerprints,
            $asset,
        ) extends AssetMetadataMutationService {
            public function __construct(
                LoopGuard $loopGuard,
                ElementAuthorization $authorization,
                AssetPropertyService $properties,
                AssetMetadataFingerprintService $fingerprints,
                private readonly Asset $asset,
            ) {
                parent::__construct($loopGuard, $authorization, $properties, $fingerprints);
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $assetId === 5 ? $this->asset : null;
            }
        };

        $result = $service->applyProperty(
            [5],
            'source',
            'text',
            'catalog',
            ['asset:5' => 'property-fingerprint'],
        );

        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['failed']);
        self::assertTrue($loopGuard->acquireAsset(5));
        $loopGuard->releaseAsset(5);
    }

    #[Test]
    public function plansBindSortedExactRequestsActorsAndMetadataTargets(): void
    {
        $actor = ActorContext::user(7);
        $tagTargets = [new \Oronts\AssetPilotBundle\Model\ApplyPlanTarget('asset:1', 'tag-fingerprint')];
        $propertyTargets = [new \Oronts\AssetPilotBundle\Model\ApplyPlanTarget('asset:1', 'property-fingerprint')];
        $fingerprints = $this->createMock(AssetMetadataFingerprintService::class);
        $fingerprints->expects(self::exactly(2))->method('planConfig')->willReturn(['version' => 1]);
        $fingerprints->expects(self::once())->method('tagTargets')->with([1, 2])->willReturn($tagTargets);
        $fingerprints->expects(self::once())
            ->method('propertyTargets')
            ->with([1, 2], 'source')
            ->willReturn($propertyTargets);
        $service = new class (
            $this->createMock(LoopGuard::class),
            $this->createMock(ElementAuthorization::class),
            $this->createMock(AssetPropertyService::class),
            $fingerprints,
        ) extends AssetMetadataMutationService {
            protected function loadTag(int $tagId, bool $force = false): ?Tag
            {
                return (new Tag())->setId($tagId);
            }
        };

        $tagPlan = $service->tagPlan($actor, [2, 1], [9, 7], true);
        $propertyPlan = $service->propertyPlan($actor, [2, 1], 'source', 'text', 'catalog');

        self::assertSame('asset-bulk-tag', $tagPlan->kind);
        self::assertSame($actor, $tagPlan->actor);
        self::assertSame(['assetIds' => [1, 2], 'replace' => true, 'tagIds' => [7, 9]], $tagPlan->request);
        self::assertSame($tagTargets, $tagPlan->targets);
        self::assertSame('asset-bulk-property', $propertyPlan->kind);
        self::assertSame(
            ['assetIds' => [1, 2], 'data' => 'catalog', 'name' => 'source', 'type' => 'text'],
            $propertyPlan->request,
        );
        self::assertSame($propertyTargets, $propertyPlan->targets);
    }

    #[Test]
    public function tagApplyLocksInSortedOrderValidatesAssignsAndReleasesInReverse(): void
    {
        $first = $this->createMock(Asset::class);
        $first->method('getId')->willReturn(1);
        $second = $this->createMock(Asset::class);
        $second->method('getId')->willReturn(2);
        $acquired = [];
        $released = [];
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::exactly(2))
            ->method('acquireAsset')
            ->willReturnCallback(static function (int $id) use (&$acquired): bool {
                $acquired[] = $id;

                return true;
            });
        $loopGuard->expects(self::exactly(2))
            ->method('releaseAsset')
            ->willReturnCallback(static function (int $id) use (&$released): void {
                $released[] = $id;
            });
        $loopGuard->expects(self::exactly(2))->method('refreshAsset');
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->expects(self::exactly(2))->method('isAllowed')->willReturn(true);
        $validated = [];
        $fingerprints = $this->createMock(AssetMetadataFingerprintService::class);
        $fingerprints->expects(self::exactly(2))
            ->method('assertTagsUnchanged')
            ->willReturnCallback(static function (Asset $asset) use (&$validated): void {
                $validated[] = $asset->getId();
            });
        $service = new class (
            $loopGuard,
            $authorization,
            $this->createMock(AssetPropertyService::class),
            $fingerprints,
            [1 => $first, 2 => $second],
        ) extends AssetMetadataMutationService {
            public array $assigned = [];

            /** @param array<int, Asset> $assets */
            public function __construct(
                LoopGuard $loopGuard,
                ElementAuthorization $authorization,
                AssetPropertyService $properties,
                AssetMetadataFingerprintService $fingerprints,
                private readonly array $assets,
            ) {
                parent::__construct($loopGuard, $authorization, $properties, $fingerprints);
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $this->assets[$assetId] ?? null;
            }

            protected function loadTag(int $tagId, bool $force = false): ?Tag
            {
                return (new Tag())->setId($tagId);
            }

            protected function assignTags(array $assetIds, array $tagIds, bool $replace): void
            {
                $this->assigned = [$assetIds, $tagIds, $replace];
            }
        };

        $service->applyTags(
            [2, 1],
            [9, 7],
            true,
            ['asset:1' => 'fingerprint-1', 'asset:2' => 'fingerprint-2'],
        );

        self::assertSame([1, 2], $acquired);
        self::assertSame([1, 2], $validated);
        self::assertSame([[1, 2], [7, 9], true], $service->assigned);
        self::assertSame([2, 1], $released);
    }

    #[Test]
    public function propertyLockedPathDoesNotReacquireTheOuterAssetLock(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(5);
        $asset->expects(self::once())->method('setProperty')->willReturnSelf();
        $asset->expects(self::once())->method('save');
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::once())->method('acquireAsset')->with(5)->willReturn(true);
        $loopGuard->expects(self::once())->method('releaseAsset')->with(5);
        $loopGuard->expects(self::once())->method('markAssetProcessing')->with(5);
        $loopGuard->expects(self::once())->method('unmarkAssetProcessing')->with(5);
        $loopGuard->expects(self::exactly(2))->method('refreshAsset')->with(5);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $fingerprints = $this->createMock(AssetMetadataFingerprintService::class);
        $fingerprints->expects(self::once())->method('assertPropertyUnchanged');
        $properties = new AssetPropertyService($loopGuard, $authorization, new NullLogger(), new EventDispatcher());
        $service = new class ($loopGuard, $authorization, $properties, $fingerprints, $asset) extends AssetMetadataMutationService {
            public function __construct(
                LoopGuard $loopGuard,
                ElementAuthorization $authorization,
                AssetPropertyService $properties,
                AssetMetadataFingerprintService $fingerprints,
                private readonly Asset $asset,
            ) {
                parent::__construct($loopGuard, $authorization, $properties, $fingerprints);
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $this->asset;
            }
        };

        $result = $service->applyProperty([5], 'source', 'text', 'catalog', ['asset:5' => 'fingerprint']);

        self::assertSame(1, $result['updated']);
    }

    #[Test]
    public function applyRejectsBusyMissingForbiddenChangedAndDeletedTagStates(): void
    {
        foreach (['busy', 'missing', 'forbidden', 'changed', 'tag'] as $scenario) {
            $asset = $this->createMock(Asset::class);
            $asset->method('getId')->willReturn(5);
            $loopGuard = $this->createMock(LoopGuard::class);
            $loopGuard->method('acquireAsset')->willReturn($scenario !== 'busy');
            if ($scenario !== 'busy') {
                $loopGuard->expects(self::once())->method('releaseAsset')->with(5);
            }
            $authorization = $this->createMock(ElementAuthorization::class);
            $authorization->method('isAllowed')->willReturn($scenario !== 'forbidden');
            $fingerprints = $this->createMock(AssetMetadataFingerprintService::class);
            if ($scenario === 'changed') {
                $fingerprints->method('assertTagsUnchanged')->willThrowException(
                    new \Oronts\AssetPilotBundle\Exception\StaleApplyPlanException('changed'),
                );
            }
            $service = new class (
                $loopGuard,
                $authorization,
                $this->createMock(AssetPropertyService::class),
                $fingerprints,
                $scenario,
                $asset,
            ) extends AssetMetadataMutationService {
                public bool $assigned = false;

                public function __construct(
                    LoopGuard $loopGuard,
                    ElementAuthorization $authorization,
                    AssetPropertyService $properties,
                    AssetMetadataFingerprintService $fingerprints,
                    private readonly string $scenario,
                    private readonly Asset $asset,
                ) {
                    parent::__construct($loopGuard, $authorization, $properties, $fingerprints);
                }

                protected function loadAsset(int $assetId): ?Asset
                {
                    return $this->scenario === 'missing' ? null : $this->asset;
                }

                protected function loadTag(int $tagId, bool $force = false): ?Tag
                {
                    return $this->scenario === 'tag' ? null : (new Tag())->setId($tagId);
                }

                protected function assignTags(array $assetIds, array $tagIds, bool $replace): void
                {
                    $this->assigned = true;
                }
            };

            try {
                $service->applyTags([5], [7], false, ['asset:5' => 'fingerprint']);
                self::fail(sprintf('Scenario "%s" did not reject the apply.', $scenario));
            } catch (\Oronts\AssetPilotBundle\Exception\StaleApplyPlanException) {
                self::assertFalse($service->assigned, $scenario);
            }
        }
    }
}
