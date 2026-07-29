<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetPropertyService;
use Oronts\AssetPilotBundle\Service\AssetProtection;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(AssetPropertyService::class)]
class AssetPropertyServiceTest extends TestCase
{
    private function service(Asset $asset, LoopGuard $loopGuard, ElementAuthorization $authorization, EventDispatcher $dispatcher): AssetPropertyService
    {
        return new class ($loopGuard, $authorization, new NullLogger(), $dispatcher, $asset) extends AssetPropertyService {
            public function __construct(LoopGuard $loopGuard, ElementAuthorization $authorization, NullLogger $logger, EventDispatcher $dispatcher, private readonly Asset $asset)
            {
                parent::__construct($loopGuard, $authorization, $logger, $dispatcher);
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $assetId === $this->asset->getId() ? $this->asset : null;
            }
        };
    }

    private function mutableAsset(): array
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(5);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->with(5)->willReturn(true);

        return [$asset, $loopGuard, $authorization];
    }

    #[Test]
    public function lockAssetUsesNativePropertySaveInsideLoopGuard(): void
    {
        [$asset, $loopGuard, $authorization] = $this->mutableAsset();
        $asset->expects(self::once())->method('setProperty')->with(AssetProtection::DEFAULT_LOCK_PROPERTY, 'bool', true)->willReturnSelf();
        $asset->expects(self::once())->method('save');
        $authorization->expects(self::exactly(2))->method('isAllowed')->with($asset, 'publish')->willReturn(true);
        $loopGuard->expects(self::once())->method('markAssetProcessing')->with(5);
        $loopGuard->expects(self::once())->method('refreshAsset')->with(5);
        $loopGuard->expects(self::once())->method('unmarkAssetProcessing')->with(5);
        $loopGuard->expects(self::once())->method('releaseAsset')->with(5);

        $dispatcher = new EventDispatcher();
        $captured = null;
        $dispatcher->addListener(AssetPilotEvents::ASSET_LOCKED, static function (AssetMutationEvent $event) use (&$captured): void {
            $captured = $event;
        });

        $this->service($asset, $loopGuard, $authorization, $dispatcher)->lockAsset(5);

        self::assertSame([5], $captured->assetIds);
    }

    #[Test]
    public function boolPropertyRejectsUnknownValueInsteadOfCoercingToFalse(): void
    {
        [$asset, $loopGuard, $authorization] = $this->mutableAsset();
        $service = $this->service($asset, $loopGuard, $authorization, new EventDispatcher());

        $this->expectException(\InvalidArgumentException::class);
        $service->bulkSetPropertyOnLockedAssets([$asset], 'flag', 'bool', 'definitely');
    }

    #[Test]
    public function unlockAssetUsesNativeRemovalAndSave(): void
    {
        [$asset, $loopGuard, $authorization] = $this->mutableAsset();
        $asset->expects(self::once())->method('removeProperty')->with(AssetProtection::DEFAULT_LOCK_PROPERTY);
        $asset->expects(self::once())->method('save');

        $dispatcher = new EventDispatcher();
        $captured = null;
        $dispatcher->addListener(AssetPilotEvents::ASSET_UNLOCKED, static function (AssetMutationEvent $event) use (&$captured): void {
            $captured = $event;
        });

        $this->service($asset, $loopGuard, $authorization, $dispatcher)->unlockAsset(5);

        self::assertSame('unlock', $captured->mutation);
    }

    #[Test]
    public function rejectsUnsupportedPropertyTypeBeforeMutation(): void
    {
        [$asset, $loopGuard, $authorization] = $this->mutableAsset();
        $asset->expects(self::never())->method('save');

        $this->expectException(\InvalidArgumentException::class);
        $this->service($asset, $loopGuard, $authorization, new EventDispatcher())
            ->setProperty(5, 'safe', 'object', '1');
    }

    #[Test]
    public function releasesGuardWhenNativeSaveFails(): void
    {
        [$asset, $loopGuard, $authorization] = $this->mutableAsset();
        $asset->method('setProperty')->willReturnSelf();
        $asset->method('save')->willThrowException(new \RuntimeException('storage unavailable'));
        $loopGuard->expects(self::once())->method('unmarkAssetProcessing')->with(5);
        $loopGuard->expects(self::once())->method('releaseAsset')->with(5);

        $this->expectException(\RuntimeException::class);
        $this->service($asset, $loopGuard, $authorization, new EventDispatcher())
            ->setProperty(5, 'safe', 'text', 'value');
    }
    #[Test]
    public function observerFailuresDoNotFailCommittedPropertyMutations(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AssetPilotEvents::ASSET_LOCKED, static function (): never {
            throw new \RuntimeException('lock observer failed');
        });
        $dispatcher->addListener(AssetPilotEvents::ASSET_UNLOCKED, static function (): never {
            throw new \RuntimeException('unlock observer failed');
        });
        $dispatcher->addListener(AssetPilotEvents::ASSET_PROPERTY_SET, static function (): never {
            throw new \RuntimeException('property observer failed');
        });

        [$asset, $loopGuard, $authorization] = $this->mutableAsset();
        $asset->expects(self::once())->method('setProperty')->willReturnSelf();
        $asset->expects(self::once())->method('save');
        $lockWarnings = $this->service($asset, $loopGuard, $authorization, $dispatcher)->lockAsset(5);
        self::assertSame(['Asset-lock observer delivery failed.'], $lockWarnings);

        [$asset, $loopGuard, $authorization] = $this->mutableAsset();
        $asset->expects(self::once())->method('removeProperty')->with(AssetProtection::DEFAULT_LOCK_PROPERTY);
        $asset->expects(self::once())->method('save');
        $unlockWarnings = $this->service($asset, $loopGuard, $authorization, $dispatcher)->unlockAsset(5);
        self::assertSame(['Asset-unlock observer delivery failed.'], $unlockWarnings);

        [$asset, $loopGuard, $authorization] = $this->mutableAsset();
        $asset->method('getRealFullPath')->willReturn('/Products/a.jpg');
        $asset->expects(self::once())->method('setProperty')->with('source', 'text', 'catalog')->willReturnSelf();
        $asset->expects(self::once())->method('save');
        $result = $this->service($asset, $loopGuard, $authorization, $dispatcher)
            ->bulkSetProperty([5], 'source', 'text', 'catalog');
        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['failed']);
        self::assertSame(['Asset-property observer delivery failed.'], $result['observerWarnings']);
    }

}
