<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Integrity\Check;

use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Integrity\IntegrityCheckerInterface;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Pimcore\Model\Asset;

/**
 * Default fallback checker (lowest priority, supports everything): the asset must have a readable,
 * non-empty binary. It catches the most basic corruption — a missing or truncated file — without
 * any rendering tool, so it always runs even when Imagick is absent.
 */
class StreamExistsChecker implements IntegrityCheckerInterface
{
    private const string NAME = 'stream_exists';

    public function priority(): int
    {
        return 0;
    }

    public function supports(Asset $asset): bool
    {
        return true;
    }

    public function check(Asset $asset): IntegrityResult
    {
        $stream = $asset->getStream();
        if (!is_resource($stream)) {
            return new IntegrityResult(IntegrityStatus::Broken, self::NAME, 'Asset has no readable binary.');
        }

        $byte = fread($stream, 1);
        fclose($stream);

        return $byte === false || $byte === ''
            ? new IntegrityResult(IntegrityStatus::Broken, self::NAME, 'Asset binary is empty.')
            : new IntegrityResult(IntegrityStatus::Renderable, self::NAME, 'A readable binary exists.');
    }

    public function checkBinary(string $binary, string $extension): IntegrityResult
    {
        return $binary === ''
            ? new IntegrityResult(IntegrityStatus::Broken, self::NAME, 'Binary is empty.')
            : new IntegrityResult(IntegrityStatus::Renderable, self::NAME);
    }
}
