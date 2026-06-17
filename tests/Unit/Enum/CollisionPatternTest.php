<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Enum;

use Oronts\AssetPilotBundle\Enum\CollisionPattern;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CollisionPattern::class)]
class CollisionPatternTest extends TestCase
{
    #[Test]
    public function exposesItsStringValues(): void
    {
        self::assertSame(['counter', 'timestamp', 'uuid'], CollisionPattern::values());
    }

    #[Test]
    public function mapsStringsToCases(): void
    {
        self::assertSame(CollisionPattern::Counter, CollisionPattern::from('counter'));
        self::assertNull(CollisionPattern::tryFrom('nope'));
    }
}
