<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\DependencyProjectionState;

readonly class DependencyProjectionStatus
{
    public function __construct(
        public DependencyProjectionState $state,
        public int $generation,
        public int $dirtySources,
        public int $sourceCount,
        public int $edgeCount,
        public ?string $cursorType,
        public int $cursorId,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $completedAt,
        public ?string $error,
    ) {}

    public function isSafe(): bool
    {
        return $this->state === DependencyProjectionState::Ready && $this->dirtySources === 0;
    }
}
