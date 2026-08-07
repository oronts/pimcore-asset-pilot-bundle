<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command\Support;

use Oronts\AssetPilotBundle\Command\Support\BoundedIntegerOption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoundedIntegerOption::class)]
final class BoundedIntegerOptionTest extends TestCase
{
    #[Test]
    public function parseAcceptsOnlyIntegersWithinTheRequestedBounds(): void
    {
        self::assertSame(1, BoundedIntegerOption::parse('1', 1, 1_000));
        self::assertSame(1_000, BoundedIntegerOption::parse('1000', 1, 1_000));
        self::assertNull(BoundedIntegerOption::parse('0', 1, 1_000));
        self::assertNull(BoundedIntegerOption::parse('1001', 1, 1_000));
        self::assertNull(BoundedIntegerOption::parse('1.5', 1, 1_000));
        self::assertNull(BoundedIntegerOption::parse(10, 1, 1_000));
    }
}
