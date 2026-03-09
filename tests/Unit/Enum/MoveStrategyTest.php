<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Enum;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoveStrategy::class)]
class MoveStrategyTest extends TestCase
{
    #[Test]
    public function hasExpectedCases(): void
    {
        $cases = MoveStrategy::cases();
        self::assertCount(3, $cases);
        self::assertSame('always', MoveStrategy::Always->value);
        self::assertSame('first_assignment', MoveStrategy::FirstAssignment->value);
        self::assertSame('callback', MoveStrategy::Callback->value);
    }

    #[Test]
    public function fromStringCreatesCorrectCase(): void
    {
        self::assertSame(MoveStrategy::Always, MoveStrategy::from('always'));
        self::assertSame(MoveStrategy::FirstAssignment, MoveStrategy::from('first_assignment'));
        self::assertSame(MoveStrategy::Callback, MoveStrategy::from('callback'));
    }

    #[Test]
    public function fromInvalidStringThrows(): void
    {
        $this->expectException(\ValueError::class);
        MoveStrategy::from('invalid');
    }
}
