<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Conversion;

use Pimcore\Model\Asset;

/**
 * Default raster converter backed by the PHP GD extension. Handles the common web image formats
 * (png, jpeg, gif, webp). It is available only when GD is loaded with support for the target format,
 * so a host without GD (or without WebP support) resolves to no converter and the caller degrades.
 */
class GdImageConverter implements AssetConverterInterface
{
    protected const array SUPPORTED = ['png', 'jpeg', 'gif', 'webp'];

    public function supports(string $targetFormat): bool
    {
        return in_array(FormatName::normalize($targetFormat), self::SUPPORTED, true);
    }

    public function isAvailable(): bool
    {
        return extension_loaded('gd');
    }

    public function convert(Asset $asset, string $targetFormat, array $options): ?string
    {
        $format = FormatName::normalize($targetFormat);
        if (!in_array($format, self::SUPPORTED, true) || !$this->formatAvailable($format)) {
            return null;
        }

        $data = $asset->getData();
        if (!is_string($data) || $data === '') {
            return null;
        }

        $image = @imagecreatefromstring($data);
        if ($image === false) {
            return null;
        }

        try {
            imagepalettetotruecolor($image);
            $quality = $this->quality($options);
            $flat = $this->flattenIfOpaqueTarget($image, $format);
            ob_start();
            $ok = match ($format) {
                'png' => imagepng($flat),
                'jpeg' => imagejpeg($flat, null, $quality),
                'gif' => imagegif($flat),
                'webp' => imagewebp($flat, null, $quality),
            };
            $encoded = ob_get_clean();
            if ($flat !== $image) {
                imagedestroy($flat);
            }

            return ($ok && is_string($encoded) && $encoded !== '') ? $encoded : null;
        } finally {
            imagedestroy($image);
        }
    }

    protected function formatAvailable(string $format): bool
    {
        $info = function_exists('gd_info') ? gd_info() : [];

        return match ($format) {
            'jpeg' => (bool) ($info['JPEG Support'] ?? false),
            'png' => (bool) ($info['PNG Support'] ?? false),
            'gif' => (bool) ($info['GIF Create Support'] ?? false),
            'webp' => (bool) ($info['WebP Support'] ?? false),
            default => false,
        };
    }

    /** @param array<string, mixed> $options */
    protected function quality(array $options): int
    {
        $quality = (int) ($options['quality'] ?? 85);

        return max(1, min(100, $quality));
    }

    /** JPEG and GIF have no alpha, so composite onto white to avoid a black background on transparent sources. */
    protected function flattenIfOpaqueTarget(\GdImage $image, string $format): \GdImage
    {
        if ($format === 'png' || $format === 'webp') {
            imagesavealpha($image, true);

            return $image;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        return $canvas;
    }
}
