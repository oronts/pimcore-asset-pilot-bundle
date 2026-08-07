<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge;

use Oronts\AssetPilotBundle\Model\ActorContext;

final readonly class DuplicateMergeContext implements DuplicateMergeContextInterface
{
    private \Closure $heartbeat;
    private \Closure $save;

    public function __construct(
        private string $operationId,
        private string $itemKey,
        private int $copyId,
        private int $canonicalId,
        private int $attempt,
        private ActorContext $actor,
        private string $idempotencyKey,
        \Closure $heartbeat,
        \Closure $save,
    ) {
        if ($operationId === '' || $itemKey === '' || $copyId <= 0 || $idempotencyKey === '') {
            throw new \InvalidArgumentException('A duplicate merge context requires stable operation, item, copy, and idempotency identifiers.');
        }
        $this->heartbeat = $heartbeat;
        $this->save = $save;
    }

    public function operationId(): string
    {
        return $this->operationId;
    }

    public function itemKey(): string
    {
        return $this->itemKey;
    }

    public function copyId(): int
    {
        return $this->copyId;
    }

    public function canonicalId(): int
    {
        return $this->canonicalId;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function actor(): ActorContext
    {
        return $this->actor;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function heartbeat(): void
    {
        ($this->heartbeat)();
    }

    public function save(callable $mutator): void
    {
        $this->heartbeat();
        ($this->save)($mutator);
    }
}
