<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Conversion;

use Oronts\AssetPilotBundle\Service\Conversion\GdImageConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;

#[CoversClass(GdImageConverter::class)]
final class GdImageConverterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('The GD extension is not available.');
        }
    }

    #[Test]
    public function supportsTheWebImageFormatsAndFoldsJpgToJpeg(): void
    {
        $converter = new GdImageConverter();

        self::assertTrue($converter->supports('png'));
        self::assertTrue($converter->supports('.JPG'), 'jpg folds to jpeg and is case/dot-insensitive');
        self::assertTrue($converter->supports('webp'));
        self::assertFalse($converter->supports('tiff'), 'an unsupported format resolves elsewhere or degrades');
    }

    #[Test]
    public function reEncodesARealPngToJpeg(): void
    {
        $png = $this->pngBytes();
        $asset = $this->imageWith($png);

        $jpeg = (new GdImageConverter())->convert($asset, 'jpeg', ['quality' => 80]);

        self::assertIsString($jpeg);
        self::assertNotSame('', $jpeg);
        self::assertSame("\xFF\xD8\xFF", substr($jpeg, 0, 3), 'output carries the JPEG SOI marker');
        self::assertNotFalse(@imagecreatefromstring($jpeg), 'the re-encoded bytes decode as a valid image');
    }

    #[Test]
    public function returnsNullForNonDecodableSourceData(): void
    {
        $asset = $this->imageWith('not-an-image');

        self::assertNull((new GdImageConverter())->convert($asset, 'png', []));
    }

    #[Test]
    public function returnsNullForAnUnsupportedTargetFormat(): void
    {
        $asset = $this->imageWith($this->pngBytes());

        self::assertNull((new GdImageConverter())->convert($asset, 'tiff', []));
    }

    private function imageWith(string $data): Asset\Image
    {
        $asset = $this->createMock(Asset\Image::class);
        $asset->method('getData')->willReturn($data);

        return $asset;
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagefilledrectangle($image, 0, 0, 1, 1, imagecolorallocate($image, 10, 20, 30));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
