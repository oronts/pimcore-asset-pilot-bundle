<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\UndoHealOutcome;
use Oronts\AssetPilotBundle\Enum\UndoHealReason;

final readonly class UndoHealResult
{
    public function __construct(
        public UndoHealOutcome $outcome,
        public ?string $reason = null,
        public bool $dryRun = false,
        public ?UndoHealReason $reasonCode = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->outcome === UndoHealOutcome::WouldReverse || $this->outcome === UndoHealOutcome::Reversed;
    }
}
