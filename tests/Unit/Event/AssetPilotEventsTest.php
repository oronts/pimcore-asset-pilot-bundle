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
    }
}
