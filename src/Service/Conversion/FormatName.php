<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Conversion;

/** Normalizes user-supplied format names to a canonical form and back to a filename extension. */
class FormatName
{
    /** Lowercase, strip a leading dot, and fold jpg -> jpeg so callers compare one canonical token. */
    public static function normalize(string $format): string
    {
        $format = strtolower(ltrim(trim($format), '.'));

        return $format === 'jpg' ? 'jpeg' : $format;
    }

    /** The filename extension for a normalized format (jpeg is written as .jpg by convention). */
    public static function extension(string $normalizedFormat): string
    {
        return $normalizedFormat === 'jpeg' ? 'jpg' : $normalizedFormat;
    }
}
