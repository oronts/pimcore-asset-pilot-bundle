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
        yield 'slug lowercases and dashes' => ["{{ 'Hello World!'|slug }}", 'hello-world'];
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
