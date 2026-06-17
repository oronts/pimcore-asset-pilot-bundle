<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Event;

use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssetPilotEvents::class)]
class AssetPilotEventsTest extends TestCase
{
    #[Test]
    public function hasExpectedEventConstants(): void
    {
        self::assertSame('oronts_asset_pilot.pre_move', AssetPilotEvents::PRE_MOVE);
        self::assertSame('oronts_asset_pilot.post_move', AssetPilotEvents::POST_MOVE);
        self::assertSame('oronts_asset_pilot.move_failed', AssetPilotEvents::MOVE_FAILED);
        self::assertSame('oronts_asset_pilot.bulk_started', AssetPilotEvents::BULK_STARTED);
        self::assertSame('oronts_asset_pilot.bulk_completed', AssetPilotEvents::BULK_COMPLETED);
        self::assertSame('oronts_asset_pilot.asset_locked', AssetPilotEvents::ASSET_LOCKED);
        self::assertSame('oronts_asset_pilot.asset_unlocked', AssetPilotEvents::ASSET_UNLOCKED);
        self::assertSame('oronts_asset_pilot.asset_property_set', AssetPilotEvents::ASSET_PROPERTY_SET);
        self::assertSame('oronts_asset_pilot.assets_tagged', AssetPilotEvents::ASSETS_TAGGED);
        self::assertSame('oronts_asset_pilot.unused_deleted', AssetPilotEvents::UNUSED_DELETED);
        self::assertSame('oronts_asset_pilot.unused_moved', AssetPilotEvents::UNUSED_MOVED);
        self::assertSame('oronts_asset_pilot.reverted', AssetPilotEvents::REVERTED);
    }
}
