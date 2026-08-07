<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Oronts\AssetPilotBundle\Service\Query\Like;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Like::class)]
class LikeTest extends TestCase
{
    #[Test]
    public function escapesWildcardsAndTheEscapeChar(): void
    {
        self::assertSame('100!% !_x', Like::escape('100% _x'));
        self::assertSame('a!!b', Like::escape('a!b'));
        self::assertSame('a\\b', Like::escape('a\\b'));
    }

    #[Test]
    public function leavesOrdinaryFolderPathsUntouched(): void
    {
        self::assertSame('/uploads/temp', Like::escape('/uploads/temp'));
    }
}
