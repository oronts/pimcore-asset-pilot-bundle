<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetZipService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(AssetZipService::class)]
class AssetZipServiceTest extends TestCase
{
    #[Test]
    public function onlyIncludesReadableNonFolderAssets(): void
    {
        $allowed = $this->createMock(Asset::class);
        $allowed->method('isAllowed')->with('view')->willReturn(true);

        $denied = $this->createMock(Asset::class);
        $denied->method('isAllowed')->with('view')->willReturn(false);

        $folder = $this->createMock(Asset\Folder::class);

        $service = $this->serviceWith([1 => $allowed, 2 => $denied, 3 => $folder, 4 => null]);

        self::assertSame([$allowed], $service->downloadable([1, 2, 3, 4]));
    }

    /** @param array<int, ?Asset> $map */
    private function serviceWith(array $map): object
    {
        $extractor = $this->createMock(AssetFieldExtractorInterface::class);

        return new class (new NullLogger(), $extractor, $map) extends AssetZipService {
            /** @param array<int, ?Asset> $map */
            public function __construct(NullLogger $logger, AssetFieldExtractorInterface $extractor, private readonly array $map)
            {
                parent::__construct($logger, $extractor);
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->map[$id] ?? null;
            }

            /**
             * @param int[] $ids
             * @return Asset[]
             */
            public function downloadable(array $ids): array
            {
                return $this->downloadableAssets($ids);
            }
        };
    }
}
