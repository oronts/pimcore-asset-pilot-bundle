<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Webpack;

use Oronts\AssetPilotBundle\Webpack\StudioBuildId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StudioBuildId::class)]
final class StudioBuildIdTest extends TestCase
{
    #[Test]
    #[DataProvider('validIds')]
    public function acceptsSafeBuildIdentifiers(string $buildId): void
    {
        self::assertTrue(StudioBuildId::isValid($buildId));
    }

    #[Test]
    #[DataProvider('invalidIds')]
    public function rejectsUnsafeOrTraversingBuildIdentifiers(string $buildId): void
    {
        self::assertFalse(StudioBuildId::isValid($buildId));
    }

    /** @return list<array{string}> */
    public static function validIds(): array
    {
        return [['development'], ['2026-07-18-abc123'], ['a.b.c'], ['a_b-c'], ['123'], ['v2.0.0']];
    }

    /** @return list<array{string}> */
    public static function invalidIds(): array
    {
        // `.` and `..` match the character class but are directory-traversal segments once used as a path part.
        return [['.'], ['..'], [''], ['a/b'], ['../etc'], ['a b'], ['a$b'], ['../../secret'], ['a\\b']];
    }
}
