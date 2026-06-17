<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Event\AssetMutationEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Service\AssetPropertyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(AssetPropertyService::class)]
class AssetPropertyServiceTest extends TestCase
{
    #[Test]
    public function lockAssetDispatchesAssetLockedEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $captured = null;
        $dispatcher->addListener(AssetPilotEvents::ASSET_LOCKED, static function (AssetMutationEvent $e) use (&$captured): void { $captured = $e; });

        $service = new AssetPropertyService($this->createMock(Connection::class), new NullLogger(), $dispatcher);
        $service->lockAsset(5, '/Products/a.jpg');

        self::assertInstanceOf(AssetMutationEvent::class, $captured);
        self::assertSame([5], $captured->assetIds);
        self::assertSame('lock', $captured->mutation);
    }

    #[Test]
    public function unlockAssetDispatchesAssetUnlockedEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $captured = null;
        $dispatcher->addListener(AssetPilotEvents::ASSET_UNLOCKED, static function (AssetMutationEvent $e) use (&$captured): void { $captured = $e; });

        $service = new AssetPropertyService($this->createMock(Connection::class), new NullLogger(), $dispatcher);
        $service->unlockAsset(5);

        self::assertInstanceOf(AssetMutationEvent::class, $captured);
        self::assertSame([5], $captured->assetIds);
        self::assertSame('unlock', $captured->mutation);
    }
}
