<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Oronts\AssetPilotBundle\Service\Query\Pagination;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(Pagination::class)]
class PaginationTest extends TestCase
{
    #[Test]
    public function appliesDefaultsWhenNoParamsGiven(): void
    {
        self::assertSame([1, 50], Pagination::fromRequest(new Request(), 200));
    }

    #[Test]
    public function usesTheGivenDefaultLimitWhenLimitIsAbsent(): void
    {
        self::assertSame([1, 20], Pagination::fromRequest(new Request(), 100, 20));
    }

    #[Test]
    public function parsesProvidedPageAndLimit(): void
    {
        self::assertSame([3, 25], Pagination::fromRequest(new Request(['page' => '3', 'limit' => '25']), 200));
    }

    #[Test]
    public function clampsPageToAtLeastOne(): void
    {
        self::assertSame([1, 50], Pagination::fromRequest(new Request(['page' => '0']), 200));
        self::assertSame([1, 50], Pagination::fromRequest(new Request(['page' => '-5']), 200));
    }

    #[Test]
    public function clampsLimitToTheMaximum(): void
    {
        self::assertSame([1, 200], Pagination::fromRequest(new Request(['limit' => '999']), 200));
    }

    #[Test]
    public function clampsLimitToAtLeastOne(): void
    {
        self::assertSame([1, 1], Pagination::fromRequest(new Request(['limit' => '0']), 200));
    }

    #[Test]
    public function toleratesNonNumericPageWithoutThrowing(): void
    {
        self::assertSame([1, 50], Pagination::fromRequest(new Request(['page' => 'foo']), 200));
    }

    #[Test]
    public function toleratesNonNumericLimitWithoutThrowing(): void
    {
        self::assertSame([1, 1], Pagination::fromRequest(new Request(['limit' => 'bar']), 200));
    }
}
