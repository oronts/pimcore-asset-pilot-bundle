<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Service\AssetMutationFingerprintService;
use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use Oronts\AssetPilotBundle\Service\DependencyUsageScannerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;

#[CoversClass(AssetMutationFingerprintService::class)]
final class AssetMutationFingerprintServiceTest extends TestCase
{
    #[Test]
    public function fingerprintBindsPathMtimeProtectionAndDependencyState(): void
    {
        $base = $this->service($this->asset('/Products/a.jpg', 100), []);
        $fingerprint = $base->fingerprintMap([7])['asset:7'];

        self::assertNotSame($fingerprint, $this->service($this->asset('/Products/b.jpg', 100), [])->fingerprintMap([7])['asset:7']);
        self::assertNotSame($fingerprint, $this->service($this->asset('/Products/a.jpg', 101), [])->fingerprintMap([7])['asset:7']);
        self::assertNotSame($fingerprint, $this->service($this->asset('/Products/a.jpg', 100, true), [])->fingerprintMap([7])['asset:7']);
        self::assertNotSame($fingerprint, $this->service($this->asset('/Products/a.jpg', 100), [['sourcetype' => 'object', 'sourceid' => 42]])->fingerprintMap([7])['asset:7']);
        self::assertNotSame($fingerprint, $this->service($this->asset('/Products/a.jpg', 100), [], liveReferenced: true)->fingerprintMap([7])['asset:7']);
        self::assertNotSame($fingerprint, $this->service($this->asset('/Products/a.jpg', 100), [], contentReferenced: true)->fingerprintMap([7])['asset:7']);
    }

    #[Test]
    public function targetIdentityIsSortedAndMissingAssetsHaveAStableSnapshot(): void
    {
        $service = $this->service($this->asset('/Products/a.jpg', 100), []);
        $targets = $service->targets([7, 3]);

        self::assertSame(['asset:3', 'asset:7'], array_column($targets, 'id'));
        self::assertSame($service->fingerprintMap([3])['asset:3'], $targets[0]->fingerprint);
    }

    #[Test]
    public function unchangedAssertionRejectsAMismatchedSnapshot(): void
    {
        $service = $this->service($this->asset('/Products/a.jpg', 100), []);

        $service->assertUnchanged(7, $service->fingerprintMap([7]));
        $this->expectException(StaleApplyPlanException::class);
        $service->assertUnchanged(7, ['asset:7' => hash('sha256', 'different')]);
    }

    private function asset(string $path, int $modifiedAt, bool $locked = false): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(7);
        $asset->method('getRealFullPath')->willReturn($path);
        $asset->method('getModificationDate')->willReturn($modifiedAt);
        $asset->method('hasProperty')->willReturn($locked);
        $asset->method('getProperty')->willReturn($locked);

        return $asset;
    }

    /** @param list<array{sourcetype: string, sourceid: int}> $dependencies */
    private function service(
        Asset $asset,
        array $dependencies,
        bool $liveReferenced = false,
        bool $contentReferenced = false,
    ): AssetMutationFingerprintService {
        $content = $this->createMock(ContentUsageScanner::class);
        $content->method('canVerify')->willReturn(true);
        $content->method('isReferencedInContent')->willReturn($contentReferenced);
        $dependency = $this->createMock(DependencyUsageScannerInterface::class);
        $dependency->method('isReferenced')->willReturn($liveReferenced);

        return new class ($this->createMock(Connection::class), $content, $dependency, $asset, $dependencies) extends AssetMutationFingerprintService {
            /** @param list<array{sourcetype: string, sourceid: int}> $dependencies */
            public function __construct(Connection $connection, ContentUsageScanner $content, DependencyUsageScannerInterface $dependency, private readonly Asset $asset, private readonly array $dependencies)
            {
                parent::__construct($connection, $content, $dependency);
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $assetId === 7 ? $this->asset : null;
            }

            protected function dependencyRows(int $assetId): array
            {
                return $this->dependencies;
            }
        };
    }
}
