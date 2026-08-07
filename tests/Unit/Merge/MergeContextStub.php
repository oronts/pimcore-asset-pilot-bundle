<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Merge;

use Oronts\AssetPilotBundle\Merge\DuplicateMergeContextInterface;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Pimcore\Model\Asset;

/**
 * Minimal fenced-context double for exercising a duplicate-merge strategy's disposeCopy() in isolation:
 * heartbeat() and save() are no-ops (the strategy under test is short and built-in), copyId() drives the
 * disposition. Strategies that call save() get the mutator invoked against the given asset if one is set.
 */
final class MergeContextStub implements DuplicateMergeContextInterface
{
    public function __construct(
        private readonly int $copyId,
        private readonly int $canonicalId = 0,
        private readonly ?Asset $asset = null,
    ) {}

    public function operationId(): string
    {
        return 'run-test';
    }

    public function itemKey(): string
    {
        return 'asset:' . $this->copyId;
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
        return 1;
    }

    public function actor(): ActorContext
    {
        return ActorContext::system();
    }

    public function idempotencyKey(): string
    {
        return 'duplicate-merge:run-test:test:' . $this->copyId;
    }

    public function heartbeat(): void {}

    /** @param callable(Asset): void $mutator */
    public function save(callable $mutator): void
    {
        if ($this->asset !== null) {
            $mutator($this->asset);
        }
    }
}
