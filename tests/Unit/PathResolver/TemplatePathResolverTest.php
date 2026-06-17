<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\PathResolver;

use Oronts\AssetPilotBundle\PathResolver\TemplatePathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

#[CoversClass(TemplatePathResolver::class)]
class TemplatePathResolverTest extends TestCase
{
    #[Test]
    public function validateTemplateAcceptsTheBuiltInFiltersAndFunctions(): void
    {
        $resolver = new TemplatePathResolver(new \Psr\Log\NullLogger());

        $resolver->validateTemplate('/Products/{{ object.getKey()|safe_key }}/{{ coalesce(a, "x") }}');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validateTemplateRejectsBrokenSyntax(): void
    {
        $resolver = new TemplatePathResolver(new \Psr\Log\NullLogger());

        $this->expectException(\Twig\Error\SyntaxError::class);
        $resolver->validateTemplate('/Products/{{ object.getKey() }');
    }

    #[Test]
    public function aTaggedTwigExtensionAddsUsableFilters(): void
    {
        $extension = new class extends AbstractExtension {
            public function getFilters(): array
            {
                return [new TwigFilter('shout', static fn (string $v): string => strtoupper($v))];
            }
        };

        $resolver = new TemplatePathResolver(new \Psr\Log\NullLogger(), [$extension]);

        // Without the extension this would throw "Unknown 'shout' filter".
        $resolver->validateTemplate('/x/{{ "a"|shout }}');
        $this->addToAssertionCount(1);
    }
}
