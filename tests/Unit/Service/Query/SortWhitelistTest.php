<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Oronts\AssetPilotBundle\Service\Query\SortWhitelist;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SortWhitelist::class)]
class SortWhitelistTest extends TestCase
{
    private const array ALLOWED = [
        'modified' => 'a.modificationDate',
        'name' => 'a.filename',
    ];

    #[Test]
    public function resolvesAKnownKeyToItsColumnAndNormalizesDescendingByDefault(): void
    {
        self::assertSame(['a.filename', 'DESC'], SortWhitelist::resolve('name', null, self::ALLOWED, 'modified'));
    }

    #[Test]
    public function honorsAscendingOrderCaseInsensitively(): void
    {
        self::assertSame(['a.filename', 'ASC'], SortWhitelist::resolve('name', 'asc', self::ALLOWED, 'modified'));
    }

    #[Test]
    public function fallsBackToTheDefaultKeyWhenSortIsUnknownOrNull(): void
    {
        self::assertSame(['a.modificationDate', 'DESC'], SortWhitelist::resolve(null, null, self::ALLOWED, 'modified'));
        self::assertSame(['a.modificationDate', 'DESC'], SortWhitelist::resolve('id; DROP TABLE assets', null, self::ALLOWED, 'modified'));
    }

    #[Test]
    public function anyNonAscOrderResolvesToDescNeverRawInput(): void
    {
        self::assertSame(['a.filename', 'DESC'], SortWhitelist::resolve('name', 'desc; DELETE', self::ALLOWED, 'modified'));
    }
}
