<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Oronts\AssetPilotBundle\Service\Query\AssetFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssetFilter::class)]
class AssetFilterTest extends TestCase
{
    #[Test]
    public function emptyFiltersProduceNoCondition(): void
    {
        self::assertSame(['', []], AssetFilter::condition([]));
    }

    #[Test]
    public function excludeFoldersAddsTheTypeGuard(): void
    {
        self::assertSame(["type != 'folder'", []], AssetFilter::condition([], excludeFolders: true));
    }

    #[Test]
    public function buildsBoundAndEscapedFolderTypeAndExtensionConditions(): void
    {
        [$condition, $params] = AssetFilter::condition(
            ['folder' => '/Products/', 'type' => 'image', 'extension' => '.jpg'],
            excludeFolders: true,
        );

        self::assertSame("type != 'folder' AND path LIKE ? AND type = ? AND filename LIKE ?", $condition);
        self::assertSame(['/Products/%', 'image', '%.jpg'], $params);
    }

    #[Test]
    public function escapesLikeWildcardsInFolderAndExtension(): void
    {
        [, $params] = AssetFilter::condition(['folder' => '/a_b/100%', 'extension' => 'j_g']);

        // % and _ from user input are escaped; the trailing/leading wildcard we add stays literal.
        self::assertSame(['/a\_b/100\%/%', '%.j\_g'], $params);
    }
}
