<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Conversion;

use Oronts\AssetPilotBundle\Service\Conversion\AssetConverterInterface;
use Oronts\AssetPilotBundle\Service\Conversion\AssetConverterResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;

#[CoversClass(AssetConverterResolver::class)]
final class AssetConverterResolverTest extends TestCase
{
    #[Test]
    public function resolvesTheFirstConverterThatSupportsAndIsAvailable(): void
    {
        $unavailable = $this->converter(supports: true, available: false);
        $winner = $this->converter(supports: true, available: true);
        $resolver = new AssetConverterResolver([$unavailable, $winner]);

        self::assertSame($winner, $resolver->resolve('webp'));
    }

    #[Test]
    public function skipsConvertersThatDoNotSupportTheFormat(): void
    {
        $wrongFormat = $this->converter(supports: false, available: true);
        $match = $this->converter(supports: true, available: true);

        self::assertSame($match, (new AssetConverterResolver([$wrongFormat, $match]))->resolve('png'));
    }

    #[Test]
    public function returnsNullWhenNothingCanProduceTheFormat(): void
    {
        $resolver = new AssetConverterResolver([
            $this->converter(supports: true, available: false),
            $this->converter(supports: false, available: true),
        ]);

        self::assertNull($resolver->resolve('avif'), 'the caller must degrade gracefully');
    }

    private function converter(bool $supports, bool $available): AssetConverterInterface
    {
        return new class ($supports, $available) implements AssetConverterInterface {
            public function __construct(private readonly bool $supports, private readonly bool $available) {}

            public function supports(string $targetFormat): bool
            {
                return $this->supports;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function convert(Asset $asset, string $targetFormat, array $options): ?string
            {
                return 'converted';
            }
        };
    }
}
