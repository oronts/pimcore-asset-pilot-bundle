<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

/**
 * The outcome of a successful revert: the asset moved back from $fromPath (where the original move
 * had placed it) to $toPath (its pre-move location).
 */
final class RevertResult
{
    public function __construct(
        public readonly int $assetId,
        public readonly string $fromPath,
        public readonly string $toPath,
    ) {}
}
