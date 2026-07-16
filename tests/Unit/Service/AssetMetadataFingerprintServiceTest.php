<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\AssetMetadataFingerprintService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\Tag;
use Pimcore\Model\Property;

#[CoversClass(AssetMetadataFingerprintService::class)]
final class AssetMetadataFingerprintServiceTest extends TestCase
{
    #[Test]
    public function tagFingerprintTracksPathModificationDateAndCurrentTagSet(): void
    {
        $asset = $this->asset();
        $firstTag = (new Tag())->setId(7);
        $secondTag = (new Tag())->setId(9);
        $service = new class ($asset, [$secondTag, $firstTag]) extends AssetMetadataFingerprintService {
            /** @param list<Tag> $tags */
            public function __construct(
                private readonly Asset $asset,
                public array $tags,
            ) {}

            protected function loadAsset(int $assetId): ?Asset
            {
                return $assetId === 5 ? $this->asset : null;
            }

            protected function loadTags(int $assetId): array
            {
                return $this->tags;
            }
        };

        $first = $service->tagTargets([5])[0]->fingerprint;
        self::assertSame(['version' => 1], $service->planConfig());
        $service->assertTagsUnchanged($asset, ['asset:5' => $first]);

        $service->tags = [$firstTag];
        $second = $service->tagTargets([5])[0]->fingerprint;

        self::assertNotSame($first, $second);
        $this->expectException(\Oronts\AssetPilotBundle\Exception\StaleApplyPlanException::class);
        $service->assertTagsUnchanged($asset, ['asset:5' => $first]);
    }

    #[Test]
    public function propertyFingerprintTracksTheNamedPropertyState(): void
    {
        $property = (new Property())
            ->setName('source')
            ->setType('text')
            ->setData('catalog')
            ->setInherited(false)
            ->setInheritable(true);
        $asset = $this->asset();
        $asset->method('getProperty')->with('source', true)->willReturn($property);
        $service = new class ($asset) extends AssetMetadataFingerprintService {
            public function __construct(private readonly Asset $asset) {}

            protected function loadAsset(int $assetId): ?Asset
            {
                return $assetId === 5 ? $this->asset : null;
            }
        };

        $first = $service->propertyTargets([5], 'source')[0]->fingerprint;
        $service->assertPropertyUnchanged($asset, 'source', ['asset:5' => $first]);

        $property->setData('campaign');
        $second = $service->propertyTargets([5], 'source')[0]->fingerprint;

        self::assertNotSame($first, $second);
        $this->expectException(\Oronts\AssetPilotBundle\Exception\StaleApplyPlanException::class);
        $service->assertPropertyUnchanged($asset, 'source', ['asset:5' => $first]);
    }

    #[Test]
    public function fingerprintChangesWithAssetPathAndModificationDate(): void
    {
        $path = '/Products/image.jpg';
        $modifiedAt = 1_234;
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(5);
        $asset->method('getRealFullPath')->willReturnCallback(static function () use (&$path): string {
            return $path;
        });
        $asset->method('getModificationDate')->willReturnCallback(static function () use (&$modifiedAt): int {
            return $modifiedAt;
        });
        $service = new class ($asset) extends AssetMetadataFingerprintService {
            public function __construct(private readonly Asset $asset) {}

            protected function loadAsset(int $assetId): ?Asset
            {
                return $this->asset;
            }

            protected function loadTags(int $assetId): array
            {
                return [];
            }
        };

        $first = $service->tagTargets([5])[0]->fingerprint;
        $path = '/Archive/image.jpg';
        $second = $service->tagTargets([5])[0]->fingerprint;
        $modifiedAt = 1_235;
        $third = $service->tagTargets([5])[0]->fingerprint;

        self::assertNotSame($first, $second);
        self::assertNotSame($second, $third);
    }

    private function asset(): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(5);
        $asset->method('getModificationDate')->willReturn(1_234);
        $asset->method('getRealFullPath')->willReturn('/Products/image.jpg');

        return $asset;
    }
}
