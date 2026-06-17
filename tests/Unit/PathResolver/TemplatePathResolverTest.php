<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\PathResolver;

use Oronts\AssetPilotBundle\PathResolver\ContextProviderInterface;
use Oronts\AssetPilotBundle\PathResolver\TemplatePathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
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

    #[Test]
    public function coreContextHasNoConsumerSpecificVariables(): void
    {
        $context = $this->contextResolver()->context($this->createMock(AbstractObject::class), $this->createMock(Asset::class));

        self::assertArrayHasKey('object', $context);
        self::assertArrayHasKey('date', $context);
        self::assertArrayNotHasKey('sapId', $context);
        self::assertArrayNotHasKey('salesOrgs', $context);
    }

    #[Test]
    public function taggedContextProvidersAddVariables(): void
    {
        $provider = new class implements ContextProviderInterface {
            public function getContext(AbstractObject $object, Asset $asset, ?string $locale): array
            {
                return ['sapId' => 'SAP1', 'region' => 'EU'];
            }
        };

        $resolver = new class(new \Psr\Log\NullLogger(), [], [$provider]) extends TemplatePathResolver {
            public function context(AbstractObject $object, Asset $asset): array
            {
                return $this->buildContext($object, $asset);
            }
        };

        $context = $resolver->context($this->createMock(AbstractObject::class), $this->createMock(Asset::class));

        self::assertSame('SAP1', $context['sapId']);
        self::assertSame('EU', $context['region']);
    }

    #[Test]
    public function coreContextKeysCannotBeOverriddenByAProvider(): void
    {
        $provider = new class implements ContextProviderInterface {
            public function getContext(AbstractObject $object, Asset $asset, ?string $locale): array
            {
                return ['object' => 'hijacked', 'className' => 'Hijacked'];
            }
        };

        $resolver = new class(new \Psr\Log\NullLogger(), [], [$provider]) extends TemplatePathResolver {
            public function context(AbstractObject $object, Asset $asset): array
            {
                return $this->buildContext($object, $asset);
            }
        };

        $object = $this->createMock(AbstractObject::class);
        $context = $resolver->context($object, $this->createMock(Asset::class));

        self::assertSame($object, $context['object']);
        self::assertSame('Folder', $context['className']);
    }

    #[Test]
    public function normalizePathKeepsAMeaningfulPath(): void
    {
        self::assertSame('/Products/SKU-1/Images', $this->pathNormalizer()->normalize('Products/SKU-1/Images', 'obj'));
    }

    #[Test]
    public function normalizePathStripsEmptySegments(): void
    {
        self::assertSame('/Products/x', $this->pathNormalizer()->normalize('//Products//x/', 'obj'));
    }

    #[Test]
    public function normalizePathCollapsesConsecutiveUnknownSegments(): void
    {
        self::assertSame('/a/unknown/b', $this->pathNormalizer()->normalize('a/unknown/unknown/b', 'obj'));
    }

    #[Test]
    public function normalizePathFallsBackWhenEmpty(): void
    {
        self::assertSame('/Assets/my-key', $this->pathNormalizer()->normalize('/', 'my-key'));
    }

    #[Test]
    public function normalizePathFallsBackWhenAllSegmentsAreUnknown(): void
    {
        self::assertSame('/Assets/my-key', $this->pathNormalizer()->normalize('unknown/unknown', 'my-key'));
    }

    #[Test]
    public function normalizePathFallsBackToUnknownWhenKeyIsNull(): void
    {
        self::assertSame('/Assets/unknown', $this->pathNormalizer()->normalize('', null));
    }

    private function pathNormalizer(): object
    {
        return new class(new \Psr\Log\NullLogger()) extends TemplatePathResolver {
            protected function sanitizeSegment(string $segment): string
            {
                return $segment;
            }

            public function normalize(string $resolved, ?string $fallbackKey): string
            {
                return $this->normalizePath($resolved, $fallbackKey);
            }
        };
    }

    private function contextResolver(): object
    {
        return new class(new \Psr\Log\NullLogger()) extends TemplatePathResolver {
            public function context(AbstractObject $object, Asset $asset): array
            {
                return $this->buildContext($object, $asset);
            }
        };
    }
}
