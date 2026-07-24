<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetMutationFingerprintService;
use Oronts\AssetPilotBundle\Service\AssetProtection;
use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\LoopGuardedAssetSaver;
use Oronts\AssetPilotBundle\Service\NormalizeFilenamesService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(NormalizeFilenamesService::class)]
class NormalizeFilenamesServiceTest extends TestCase
{
    private function asset(int $id, string $filename, bool $allowed = true, string $folder = '/uploads'): Asset&MockObject
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn($id);
        $asset->method('getFilename')->willReturn($filename);
        $asset->method('getRealFullPath')->willReturn(rtrim($folder, '/') . '/' . $filename);
        $asset->method('isAllowed')->willReturn($allowed);

        return $asset;
    }

    private function scanner(bool $canVerify = true, bool $referenced = false): ContentUsageScanner&MockObject
    {
        $scanner = $this->createMock(ContentUsageScanner::class);
        $scanner->method('canVerify')->willReturn($canVerify);
        $scanner->method('isReferencedInContent')->willReturn($referenced);

        return $scanner;
    }

    private function loopGuard(bool $assetLock = true, bool $targetLock = true): LoopGuard&MockObject
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn($assetLock);
        $loopGuard->method('acquireTarget')->willReturn($targetLock);

        return $loopGuard;
    }

    private function authorization(): ElementAuthorization&MockObject
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (Asset $asset, string $permission): bool => $asset->isAllowed($permission),
        );

        return $authorization;
    }

    private function fingerprints(): AssetMutationFingerprintService&MockObject
    {
        return $this->createMock(AssetMutationFingerprintService::class);
    }

    /**
     * @param array<int, ?Asset>     $assetsById
     * @param array<string, string>  $validKeys   current filename => normalized form
     * @param \ArrayObject<int, array{0: int, 1: string, 2: string}> $renamed
     * @param array<string, Asset>                         $assetsByPath
     */
    private function service(
        array $assetsById,
        array $validKeys,
        \ArrayObject $renamed,
        ?ContentUsageScanner $scanner = null,
        ?LoopGuard $loopGuard = null,
        array $assetsByPath = [],
        ?AssetMutationFingerprintService $fingerprints = null,
    ): NormalizeFilenamesService {
        return new class ($loopGuard ?? $this->loopGuard(), $scanner ?? $this->scanner(), $this->authorization(), $fingerprints ?? $this->fingerprints(), $assetsById, $validKeys, $renamed, $assetsByPath) extends NormalizeFilenamesService {
            /**
             * @param array<int, ?Asset>    $assetsById
             * @param array<string, string> $validKeys
             * @param \ArrayObject<int, array{0: int, 1: string, 2: string}> $renamed
             * @param array<string, Asset> $assetsByPath
             */
            public function __construct(
                LoopGuard $lg,
                ContentUsageScanner $cs,
                ElementAuthorization $authorization,
                AssetMutationFingerprintService $fingerprints,
                private readonly array $assetsById,
                private readonly array $validKeys,
                private readonly \ArrayObject $renamed,
                private readonly array $assetsByPath,
            ) {
                parent::__construct($lg, new NullLogger(), $cs, $authorization, $fingerprints, new LoopGuardedAssetSaver($lg));
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->assetsById[$id] ?? null;
            }

            protected function reloadAsset(int $id): ?Asset
            {
                return $this->assetsById[$id] ?? null;
            }

            protected function assetAtPath(string $path): ?Asset
            {
                return $this->assetsByPath[$path] ?? null;
            }

            protected function validKey(string $filename): string
            {
                return $this->validKeys[$filename] ?? $filename;
            }

            protected function renameGuarded(Asset $asset, int $assetId, string $filename, string $targetPath): void
            {
                $this->renamed->append([$assetId, $filename, $targetPath]);
            }
        };
    }

    #[Test]
    public function renamesAnInvalidFilename(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service([1 => $this->asset(1, 'My File.JPG')], ['My File.JPG' => 'my-file.jpg'], $renamed)
            ->normalize([1], dryRun: false);

        self::assertSame(1, $result['renamed']);
        self::assertSame([[1, 'my-file.jpg', '/uploads/my-file.jpg']], $renamed->getArrayCopy());
        self::assertSame([['id' => 1, 'from' => 'My File.JPG', 'to' => 'my-file.jpg']], $result['changes']);
    }

    #[Test]
    public function skipsAnAlreadyValidFilename(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service([1 => $this->asset(1, 'already-valid.jpg')], [], $renamed)->normalize([1], dryRun: false);

        self::assertSame(0, $result['renamed']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function dryRunRecordsTheChangeButDoesNotRename(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service([1 => $this->asset(1, 'My File.JPG')], ['My File.JPG' => 'my-file.jpg'], $renamed)
            ->normalize([1], dryRun: true);

        self::assertSame(0, $result['renamed']);
        self::assertCount(1, $result['changes']);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function failsWhenTheAclDeniesTheRename(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service([1 => $this->asset(1, 'My File.JPG', allowed: false)], ['My File.JPG' => 'my-file.jpg'], $renamed)
            ->normalize([1], dryRun: false);

        self::assertSame(0, $result['renamed']);
        self::assertSame(1, $result['failed']);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function failsWhenTheAssetIsProtected(): void
    {
        $asset = $this->asset(1, 'My File.JPG');
        $asset->method('hasProperty')->with(AssetProtection::DEFAULT_LOCK_PROPERTY)->willReturn(true);
        $asset->method('getProperty')->with(AssetProtection::DEFAULT_LOCK_PROPERTY)->willReturn(true);
        $renamed = new \ArrayObject();

        $result = $this->service([1 => $asset], ['My File.JPG' => 'my-file.jpg'], $renamed)
            ->normalize([1], dryRun: false);

        self::assertSame(0, $result['renamed']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('protected', $result['errors'][1]);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function revalidatesTheReviewedFingerprintUnderTheAssetLock(): void
    {
        $fingerprints = $this->fingerprints();
        $fingerprints->expects(self::once())->method('assertUnchanged')
            ->with(1, ['asset:1' => 'reviewed'])
            ->willThrowException(new StaleApplyPlanException('Asset changed after preview'));
        $renamed = new \ArrayObject();

        $result = $this->service(
            [1 => $this->asset(1, 'My File.JPG')],
            ['My File.JPG' => 'my-file.jpg'],
            $renamed,
            fingerprints: $fingerprints,
        )->normalize([1], dryRun: false, expectedFingerprints: ['asset:1' => 'reviewed']);

        self::assertSame(0, $result['renamed']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('changed after preview', $result['errors'][1]);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function failsWhenTheAssetIsReferencedInContent(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service([1 => $this->asset(1, 'My File.JPG')], ['My File.JPG' => 'my-file.jpg'], $renamed, $this->scanner(referenced: true))
            ->normalize([1], dryRun: false);

        self::assertSame(0, $result['renamed']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('content', $result['errors'][1]);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function previewAndApplyFailClosedWithoutContentVerification(): void
    {
        foreach ([true, false] as $dryRun) {
            $renamed = new \ArrayObject();
            $result = $this->service(
                [1 => $this->asset(1, 'My File.JPG')],
                ['My File.JPG' => 'my-file.jpg'],
                $renamed,
                $this->scanner(canVerify: false),
            )->normalize([1], $dryRun);

            self::assertSame(0, $result['renamed']);
            self::assertSame(1, $result['failed']);
            self::assertSame([], $result['changes']);
            self::assertStringContainsString('not configured', $result['errors'][1]);
            self::assertSame([], $renamed->getArrayCopy());
        }
    }

    #[Test]
    public function failsWhenTheAssetLockCannotBeAcquired(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service(
            [1 => $this->asset(1, 'My File.JPG')],
            ['My File.JPG' => 'my-file.jpg'],
            $renamed,
            loopGuard: $this->loopGuard(assetLock: false),
        )->normalize([1], dryRun: false);

        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('another job', $result['errors'][1]);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function failsWhenTheTargetLockCannotBeAcquired(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service(
            [1 => $this->asset(1, 'My File.JPG')],
            ['My File.JPG' => 'my-file.jpg'],
            $renamed,
            loopGuard: $this->loopGuard(targetLock: false),
        )->normalize([1], dryRun: false);

        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('Target path', $result['errors'][1]);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function failsWhenTheTargetPathIsOccupied(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service(
            [1 => $this->asset(1, 'My File.JPG')],
            ['My File.JPG' => 'my-file.jpg'],
            $renamed,
            assetsByPath: ['/uploads/my-file.jpg' => $this->asset(2, 'my-file.jpg')],
        )->normalize([1], dryRun: false);

        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('already exists', $result['errors'][1]);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function appliedRenameRefreshesAndReleasesBothLocks(): void
    {
        $asset = $this->asset(1, 'My File.JPG');
        $asset->expects(self::once())->method('setFilename')->with('my-file.jpg');
        $asset->expects(self::once())->method('save');

        $loopGuard = $this->loopGuard();
        $loopGuard->expects(self::once())->method('refreshAsset')->with(1);
        $loopGuard->expects(self::once())->method('refreshTarget')->with('/uploads/my-file.jpg');
        $loopGuard->expects(self::once())->method('markAssetProcessing')->with(1);
        $loopGuard->expects(self::once())->method('markAssetRecentlyMoved')->with(1);
        $loopGuard->expects(self::once())->method('unmarkAssetProcessing')->with(1);
        $loopGuard->expects(self::once())->method('releaseTarget')->with('/uploads/my-file.jpg');
        $loopGuard->expects(self::once())->method('releaseAsset')->with(1);

        $service = new class ($loopGuard, $this->scanner(), $this->authorization(), $this->fingerprints(), $asset) extends NormalizeFilenamesService {
            public function __construct(LoopGuard $loopGuard, ContentUsageScanner $scanner, ElementAuthorization $authorization, AssetMutationFingerprintService $fingerprints, private readonly Asset $asset)
            {
                parent::__construct($loopGuard, new NullLogger(), $scanner, $authorization, $fingerprints, new LoopGuardedAssetSaver($loopGuard));
            }

            protected function reloadAsset(int $id): ?Asset
            {
                return $id === 1 ? $this->asset : null;
            }

            protected function assetAtPath(string $path): ?Asset
            {
                return null;
            }

            protected function validKey(string $filename): string
            {
                return 'my-file.jpg';
            }
        };

        $result = $service->normalize([1], dryRun: false);

        self::assertSame(1, $result['renamed']);
    }
}
