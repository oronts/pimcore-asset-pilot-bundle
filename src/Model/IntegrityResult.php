<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\IntegrityStatus;

readonly class IntegrityResult
{
    public function __construct(
        public IntegrityStatus $status,
        public string $checker,
        public ?string $reason = null,
    ) {}

    public function isRenderable(): bool
    {
        return $this->status === IntegrityStatus::Renderable;
    }

    public function isBroken(): bool
    {
        return $this->status === IntegrityStatus::Broken;
    }
}
