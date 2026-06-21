<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Integrity\Check;

use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Pimcore\Model\Asset;

/**
 * Verifies an image decodes and has at least one frame via Imagick. When Imagick is absent the
 * result is Unverifiable (never Broken), so the feature never destroys an image because the tool was
 * missing.
 */
class ImageIntegrityChecker extends AbstractBinaryIntegrityChecker
{
    private const string NAME = 'image';

    public function priority(): int
    {
        return 20;
    }

    public function supports(Asset $asset): bool
    {
        return $asset instanceof Asset\Image;
    }

    public function checkBinary(string $binary, string $extension): IntegrityResult
    {
        return $this->verifyWithImagick(
            $binary,
            $extension,
            static function (\Imagick $imagick, string $blob): int {
                $imagick->readImageBlob($blob);

                return $imagick->getNumberImages();
            },
            'Image data could not be decoded.',
        );
    }

    protected function name(): string
    {
        return self::NAME;
    }
}
