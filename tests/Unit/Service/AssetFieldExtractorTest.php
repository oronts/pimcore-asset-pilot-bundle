<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Data\ElementMetadata;
use Pimcore\Model\DataObject\Data\Hotspotimage;
use Psr\Log\NullLogger;

#[CoversClass(AssetFieldExtractor::class)]
class AssetFieldExtractorTest extends TestCase
{
    private object $extractor;

    protected function setUp(): void
    {
        $this->extractor = new class(new NullLogger()) extends AssetFieldExtractor {
            public function extractFrom(mixed $value): array
            {
                return $this->extractAssetsFromValue($value);
            }
        };
    }

    #[Test]
    public function unwrapsAdvancedRelationElementMetadataToTheAsset(): void
    {
        $asset = $this->createMock(Asset::class);
        $meta = $this->createMock(ElementMetadata::class);
        $meta->method('getElement')->willReturn($asset);

        // advancedMany*Relation returns an array of ElementMetadata.
        self::assertSame([$asset], $this->extractor->extractFrom([$meta]));
    }

    #[Test]
    public function elementMetadataWrappingANonAssetYieldsNothing(): void
    {
        $meta = $this->createMock(ElementMetadata::class);
        $meta->method('getElement')->willReturn(null);

        self::assertSame([], $this->extractor->extractFrom($meta));
    }

    #[Test]
    public function extractsADirectAsset(): void
    {
        $asset = $this->createMock(Asset::class);

        self::assertSame([$asset], $this->extractor->extractFrom($asset));
    }

    #[Test]
    public function extractsTheImageOutOfAHotspotImage(): void
    {
        $image = $this->createMock(Asset\Image::class);
        $hotspot = $this->createMock(Hotspotimage::class);
        $hotspot->method('getImage')->willReturn($image);

        self::assertSame([$image], $this->extractor->extractFrom($hotspot));
    }

    #[Test]
    public function nullYieldsNothing(): void
    {
        self::assertSame([], $this->extractor->extractFrom(null));
    }

    #[Test]
    public function emptyConfiguredLocalesScansAllValidLanguages(): void
    {
        self::assertSame(['en', 'de'], $this->localeResolver([], ['en', 'de'])->locales());
    }

    #[Test]
    public function configuredLocalesAreIntersectedWithValidLanguages(): void
    {
        self::assertSame(['de', 'fr'], $this->localeResolver(['de', 'fr', 'xx'], ['en', 'de', 'fr'])->locales());
    }

    /**
     * @param string[] $configured
     * @param string[] $valid
     */
    private function localeResolver(array $configured, array $valid): object
    {
        return new class(new NullLogger(), $configured, $valid) extends AssetFieldExtractor {
            /**
             * @param string[] $configured
             * @param string[] $valid
             */
            public function __construct(NullLogger $logger, array $configured, private readonly array $valid)
            {
                parent::__construct($logger, $configured);
            }

            protected function validLanguages(): array
            {
                return $this->valid;
            }

            /** @return string[] */
            public function locales(): array
            {
                return $this->resolveLocales();
            }
        };
    }
}
