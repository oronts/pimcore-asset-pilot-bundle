<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\NormalizeFilenamesService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(NormalizeFilenamesService::class)]
class NormalizeFilenamesServiceTest extends TestCase
{
    private function asset(string $filename, bool $allowed = true): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getFilename')->willReturn($filename);
        $asset->method('isAllowed')->willReturn($allowed);

        return $asset;
    }

    /**
     * @param array<int, ?Asset>     $assetsById
     * @param array<string, string>  $validKeys   current filename => normalized form
     * @param \ArrayObject<int, array{0: int, 1: string}> $renamed
     */
    private function service(array $assetsById, array $validKeys, \ArrayObject $renamed, ?ContentUsageScanner $scanner = null): NormalizeFilenamesService
    {
        return new class ($this->createMock(LoopGuard::class), $scanner, $assetsById, $validKeys, $renamed) extends NormalizeFilenamesService {
            /**
             * @param array<int, ?Asset>    $assetsById
             * @param array<string, string> $validKeys
             * @param \ArrayObject<int, array{0: int, 1: string}> $renamed
             */
            public function __construct(LoopGuard $lg, ?ContentUsageScanner $cs, private readonly array $assetsById, private readonly array $validKeys, private readonly \ArrayObject $renamed)
            {
                parent::__construct($lg, new NullLogger(), $cs);
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $this->assetsById[$id] ?? null;
            }

            protected function validKey(string $filename): string
            {
                return $this->validKeys[$filename] ?? $filename;
            }

            protected function renameGuarded(Asset $asset, int $assetId, string $filename): void
            {
                $this->renamed->append([$assetId, $filename]);
            }
        };
    }

    #[Test]
    public function renamesAnInvalidFilename(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service([1 => $this->asset('My File.JPG')], ['My File.JPG' => 'my-file.jpg'], $renamed)
            ->normalize([1], dryRun: false);

        self::assertSame(1, $result['renamed']);
        self::assertSame([[1, 'my-file.jpg']], $renamed->getArrayCopy());
        self::assertSame([['id' => 1, 'from' => 'My File.JPG', 'to' => 'my-file.jpg']], $result['changes']);
    }

    #[Test]
    public function skipsAnAlreadyValidFilename(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service([1 => $this->asset('already-valid.jpg')], [], $renamed)->normalize([1], dryRun: false);

        self::assertSame(0, $result['renamed']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function dryRunRecordsTheChangeButDoesNotRename(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service([1 => $this->asset('My File.JPG')], ['My File.JPG' => 'my-file.jpg'], $renamed)
            ->normalize([1], dryRun: true);

        self::assertSame(0, $result['renamed']);
        self::assertCount(1, $result['changes']);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function failsWhenTheAclDeniesTheRename(): void
    {
        $renamed = new \ArrayObject();
        $result = $this->service([1 => $this->asset('My File.JPG', allowed: false)], ['My File.JPG' => 'my-file.jpg'], $renamed)
            ->normalize([1], dryRun: false);

        self::assertSame(0, $result['renamed']);
        self::assertSame(1, $result['failed']);
        self::assertSame([], $renamed->getArrayCopy());
    }

    #[Test]
    public function failsWhenTheAssetIsReferencedInContent(): void
    {
        $scanner = $this->createMock(ContentUsageScanner::class);
        $scanner->method('isReferencedInContent')->willReturn(true);

        $renamed = new \ArrayObject();
        $result = $this->service([1 => $this->asset('My File.JPG')], ['My File.JPG' => 'my-file.jpg'], $renamed, $scanner)
            ->normalize([1], dryRun: false);

        self::assertSame(0, $result['renamed']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('content', $result['errors'][1]);
        self::assertSame([], $renamed->getArrayCopy());
    }
}
