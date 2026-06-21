<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;

/**
 * The fate of one duplicate copy after a merge: what happened to it and, when it was left in place,
 * why.
 */
readonly class CopyDisposition
{
    public function __construct(
        public int $copyId,
        public DispositionOutcome $outcome,
        public string $reason = '',
    ) {}
}
