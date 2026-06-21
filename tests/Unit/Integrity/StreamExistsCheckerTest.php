<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Integrity;

use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Integrity\Check\StreamExistsChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;

#[CoversClass(StreamExistsChecker::class)]
class StreamExistsCheckerTest extends TestCase
{
    #[Test]
    public function brokenWhenTheAssetHasNoStream(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getStream')->willReturn(null);

        self::assertSame(IntegrityStatus::Broken, (new StreamExistsChecker())->check($asset)->status);
    }

    #[Test]
    public function renderableWhenAReadableNonEmptyBinaryExists(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'content');
        rewind($stream);

        $asset = $this->createMock(Asset::class);
        $asset->method('getStream')->willReturn($stream);

        self::assertSame(IntegrityStatus::Renderable, (new StreamExistsChecker())->check($asset)->status);
    }

    #[Test]
    public function checkBinaryFlagsEmptyBytes(): void
    {
        $checker = new StreamExistsChecker();

        self::assertSame(IntegrityStatus::Broken, $checker->checkBinary('', 'bin')->status);
        self::assertSame(IntegrityStatus::Renderable, $checker->checkBinary('x', 'bin')->status);
    }
}
