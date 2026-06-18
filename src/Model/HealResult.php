<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\HealOutcome;

readonly class HealResult
{
    public function __construct(
        public HealOutcome $outcome,
        public string $checker,
        public ?int $toVersion = null,
        public ?string $reason = null,
        public bool $dryRun = false,
    ) {}

    public function isHealed(): bool
    {
        return $this->outcome === HealOutcome::Healed;
    }
}
