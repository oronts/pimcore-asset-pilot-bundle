<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Integrity\Check;

use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Pimcore\Model\Asset;

/**
 * Verifies a document (e.g. PDF) parses and has at least one page via Imagick's lightweight
 * ping (header/metadata only, so it does not render every page into memory). Unverifiable when
 * Imagick is absent.
 */
class DocumentIntegrityChecker extends AbstractBinaryIntegrityChecker
{
    private const string NAME = 'document';

    public function priority(): int
    {
        return 20;
    }

    public function supports(Asset $asset): bool
    {
        return $asset instanceof Asset\Document;
    }

    public function checkBinary(string $binary, string $extension): IntegrityResult
    {
        return $this->verifyWithImagick(
            $binary,
            $extension,
            static function (\Imagick $imagick, string $blob): int {
                $imagick->pingImageBlob($blob);

                return $imagick->getNumberImages();
            },
            'Document could not be parsed.',
        );
    }

    protected function name(): string
    {
        return self::NAME;
    }
}
