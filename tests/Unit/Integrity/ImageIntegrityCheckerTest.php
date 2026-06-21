<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Integrity;

use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Integrity\Check\ImageIntegrityChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageIntegrityChecker::class)]
class ImageIntegrityCheckerTest extends TestCase
{
    private function validPng(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    #[Test]
    #[RequiresPhpExtension('imagick')]
    #[RequiresPhpExtension('gd')]
    public function aValidImageIsRenderable(): void
    {
        $result = (new ImageIntegrityChecker())->checkBinary($this->validPng(), 'png');

        self::assertSame(IntegrityStatus::Renderable, $result->status);
    }

    #[Test]
    #[RequiresPhpExtension('imagick')]
    public function garbageBytesAreBroken(): void
    {
        $result = (new ImageIntegrityChecker())->checkBinary('this is not an image', 'png');

        self::assertSame(IntegrityStatus::Broken, $result->status);
    }

    #[Test]
    public function unverifiableWhenImagickIsUnavailable(): void
    {
        $checker = new class () extends ImageIntegrityChecker {
            protected function imagickAvailable(): bool
            {
                return false;
            }
        };

        $result = $checker->checkBinary('any bytes', 'png');

        self::assertSame(IntegrityStatus::Unverifiable, $result->status);
    }

    #[Test]
    public function anUnsupportedFormatIsUnverifiableNotBroken(): void
    {
        $checker = new class () extends ImageIntegrityChecker {
            protected function imagickAvailable(): bool
            {
                return true;
            }

            protected function queryFormats(string $pattern): array
            {
                return [];
            }
        };

        $result = $checker->checkBinary('any bytes', 'heic');

        self::assertSame(IntegrityStatus::Unverifiable, $result->status);
    }

    #[Test]
    public function aVerifierInitFailureIsUnverifiableNotBroken(): void
    {
        $checker = new class () extends ImageIntegrityChecker {
            protected function imagickAvailable(): bool
            {
                return true;
            }

            protected function queryFormats(string $pattern): array
            {
                return ['PNG'];
            }

            protected function newImagick(): \Imagick
            {
                throw new \RuntimeException('imagick init failed');
            }
        };

        $result = $checker->checkBinary('any bytes', 'png');

        self::assertSame(IntegrityStatus::Unverifiable, $result->status);
    }

    #[Test]
    #[RequiresPhpExtension('imagick')]
    public function aToolOrPolicyErrorIsUnverifiableButAGenuineDecodeFailureIsBroken(): void
    {
        $checker = new class () extends ImageIntegrityChecker {
            protected function queryFormats(string $pattern): array
            {
                return ['PNG'];
            }

            public function verify(callable $decode): IntegrityStatus
            {
                return $this->verifyWithImagick('bytes', 'png', $decode, 'broken')->status;
            }
        };

        $policy = $checker->verify(static fn (): int => throw new \ImagickException("attempt to perform an operation not allowed by the security policy `PDF'"));
        self::assertSame(IntegrityStatus::Unverifiable, $policy);

        $corrupt = $checker->verify(static fn (): int => throw new \RuntimeException('improper image header'));
        self::assertSame(IntegrityStatus::Broken, $corrupt);
    }
}
