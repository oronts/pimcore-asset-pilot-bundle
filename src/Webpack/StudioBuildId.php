<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Webpack;

/**
 * The single Studio build-identifier contract enforced on the PHP side.
 *
 * A build id is used verbatim as a path segment under the build root (`<buildRoot>/<buildId>/...`),
 * so a regex-clean value that is still `.` or `..` must be rejected to prevent directory traversal.
 * This mirrors the canonical JS validator in `assets/studio/scripts/manifest-assets.mjs`
 * (`isValidBuildId`); the parity is covered by tests on both sides.
 */
class StudioBuildId
{
    private const string PATTERN = '/^[A-Za-z0-9._-]+$/D';

    public static function isValid(string $buildId): bool
    {
        return preg_match(self::PATTERN, $buildId) === 1
            && $buildId !== '.'
            && $buildId !== '..';
    }
}
