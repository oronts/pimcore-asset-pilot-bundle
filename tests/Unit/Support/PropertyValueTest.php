<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Support;

use Oronts\AssetPilotBundle\Enum\PropertyType;
use Oronts\AssetPilotBundle\Support\PropertyValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PropertyValue::class)]
class PropertyValueTest extends TestCase
{
    /** @return iterable<string, array{string|bool|int, bool}> */
    public static function documentedBoolForms(): iterable
    {
        yield 'native true' => [true, true];
        yield 'native false' => [false, false];
        yield 'string true' => ['true', true];
        yield 'string false' => ['false', false];
        yield 'string 1' => ['1', true];
        yield 'string 0' => ['0', false];
        yield 'string on' => ['on', true];
        yield 'string off' => ['off', false];
        yield 'string yes' => ['yes', true];
        yield 'string no' => ['no', false];
    }

    #[Test]
    #[DataProvider('documentedBoolForms')]
    public function normalizesEveryDocumentedBoolForm(string|bool|int $raw, bool $expected): void
    {
        self::assertSame($expected, PropertyValue::normalize(PropertyType::Bool, $raw));
    }

    #[Test]
    public function rejectsAnUnknownBoolValueInsteadOfCoercingToFalse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PropertyValue::normalize(PropertyType::Bool, 'definitely');
    }

    /** @return iterable<string, array{string}> */
    public static function ambiguousBoolStrings(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    #[Test]
    #[DataProvider('ambiguousBoolStrings')]
    public function rejectsAnEmptyOrWhitespaceBoolValue(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PropertyValue::normalize(PropertyType::Bool, $raw);
    }

    #[Test]
    public function keepsTextAndSelectValuesAsStrings(): void
    {
        self::assertSame('catalog', PropertyValue::normalize(PropertyType::Text, 'catalog'));
        self::assertSame('42', PropertyValue::normalize(PropertyType::Select, 42));
        self::assertSame('1', PropertyValue::normalize(PropertyType::Text, true));
    }
}
