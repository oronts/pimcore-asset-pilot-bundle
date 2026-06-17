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
