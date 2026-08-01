<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\PathResolver;

use Oronts\AssetPilotBundle\PathResolver\PathTemplateExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(PathTemplateExtension::class)]
class PathTemplateExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function templates(): iterable
    {
        yield 'safe_key sanitizes' => ["{{ 'a/b c.x'|safe_key }}", 'a-b-c.x'];
        yield 'safe_key preserves the literal zero' => ["{{ '0'|safe_key }}", '0'];
        yield 'slug lowercases and dashes' => ["{{ 'Hello World!'|slug }}", 'hello-world'];
        yield 'slug preserves the literal zero' => ["{{ '0'|slug }}", '0'];
        yield 'fallback catches empty string' => ["{{ ''|fallback('def') }}", 'def'];
        yield 'trim_path strips slashes' => ["{{ '/a/b/'|trim_path }}", 'a/b'];
        yield 'coalesce picks first non-empty' => ["{{ coalesce('', null, 'x') }}", 'x'];
    }

    #[Test]
    #[DataProvider('templates')]
    public function rendersBuiltInHelpers(string $template, string $expected): void
    {
        self::assertSame($expected, $this->render($template));
    }

    #[Test]
    public function propAllowsReadAccessorsOnly(): void
    {
        $obj = new class () {
            public function getSku(): string
            {
                return 'ABC';
            }

            public function deleteEverything(): string
            {
                return 'boom';
            }
        };

        self::assertSame('ABC', $this->render("{{ prop(o, 'getSku') }}", ['o' => $obj]));
        self::assertSame('', $this->render("{{ prop(o, 'deleteEverything') }}", ['o' => $obj]));
    }

    #[Test]
    public function firstOfAndPluckTreatBooleanFalseLikeTheOtherFilters(): void
    {
        // A boolean-false accessor must map to the fallback/skip (like coalesce/fallback/safe_key), not a "" that
        // gets dropped as an empty path segment and silently mis-files the asset one directory up.
        $obj = new class () {
            public function getFlag(): bool
            {
                return false;
            }
        };

        self::assertSame('unknown', $this->render("{{ [o]|first_of('flag') }}", ['o' => $obj]));
        self::assertSame('0', $this->render("{{ [o]|pluck('flag')|length }}", ['o' => $obj]));
    }

    #[Test]
    public function pluckAndFirstOfInvokeReadAccessorsOnly(): void
    {
        $flag = new \ArrayObject(['mutated' => false]);
        $item = new class ($flag) {
            public function __construct(private readonly \ArrayObject $flag) {}

            public function getCode(): string
            {
                return 'C1';
            }

            public function drop(): string
            {
                $this->flag['mutated'] = true;

                return 'boom';
            }
        };
        $items = [$item];

        self::assertSame('C1', $this->render("{{ items|first_of('code') }}", ['items' => $items]));
        self::assertSame('C1', $this->render("{{ items|pluck('code')|first }}", ['items' => $items]));

        self::assertSame('unknown', $this->render("{{ items|first_of('drop') }}", ['items' => $items]));
        self::assertSame('x', $this->render("{{ items|pluck('drop')|first|default('x') }}", ['items' => $items]));
        self::assertFalse($flag['mutated'], 'A path-template filter must never invoke a non-accessor (mutating) method.');
    }

    #[Test]
    public function readAccessorsIgnoreNonPublicAndArgumentRequiringMembers(): void
    {
        $obj = new class () {
            public string $publicName = 'pub';
            protected string $secret = 'nope';

            public function getCode(): string
            {
                return 'C1';
            }

            protected function getHidden(): string
            {
                return 'hidden';
            }

            public function getWithArg(string $x): string
            {
                return $x;
            }
        };
        $items = ['items' => [$obj]];

        self::assertSame('C1', $this->render("{{ items|first_of('code') }}", $items));
        self::assertSame('pub', $this->render("{{ items|first_of('publicName') }}", $items));
        self::assertSame('unknown', $this->render("{{ items|first_of('hidden') }}", $items));
        self::assertSame('unknown', $this->render("{{ items|first_of('secret') }}", $items));
        self::assertSame('unknown', $this->render("{{ items|first_of('getWithArg') }}", $items));
    }

    #[Test]
    public function anUninitializedPublicTypedPropertyFallsBackInsteadOfThrowing(): void
    {
        $obj = new class () {
            public string $name;

            public function getCode(): string
            {
                return 'C1';
            }
        };
        $items = ['items' => [$obj]];

        self::assertSame('unknown', $this->render("{{ items|first_of('name') }}", $items));
        self::assertSame('x', $this->render("{{ items|pluck('name')|first|default('x') }}", $items));
        self::assertSame('C1', $this->render("{{ items|first_of('code') }}", $items));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context = []): string
    {
        $twig = new Environment(new ArrayLoader(['t' => $template]), ['autoescape' => false, 'cache' => false]);
        $twig->addExtension(new PathTemplateExtension());

        return $twig->render('t', $context);
    }
}
