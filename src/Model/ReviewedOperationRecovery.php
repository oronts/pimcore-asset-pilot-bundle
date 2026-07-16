<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

final readonly class ReviewedOperationRecovery
{
    /** @param list<OperationRecoveryResult> $results */
    public function __construct(
        public array $results,
        public ?string $planToken,
        public bool $applied,
    ) {
        if ($this->results === [] && $this->planToken !== null) {
            throw new \InvalidArgumentException('An empty recovery review cannot have a plan token.');
        }
        if ($this->applied && $this->planToken !== null) {
            throw new \InvalidArgumentException('An applied recovery cannot return a plan token.');
        }
    }

    public function unresolvedCount(): int
    {
        return count(array_filter(
            $this->results,
            static fn (OperationRecoveryResult $result): bool => !$result->isResolved(),
        ));
    }
}
