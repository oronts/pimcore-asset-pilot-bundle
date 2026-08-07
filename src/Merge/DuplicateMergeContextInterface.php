<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Pimcore\Model\Asset;

/**
 * The fenced execution context handed to a context-aware duplicate-merge strategy so a long or retried
 * disposition stays safe: heartbeat() renews the run item and every held asset/referrer lock together and
 * fails closed the instant any is lost; idempotencyKey() is stable across attempt, resume, and retry so
 * external side effects can dedupe; save() performs a guarded write of the copy under the held lock.
 */
interface DuplicateMergeContextInterface
{
    public function operationId(): string;

    public function itemKey(): string;

    public function copyId(): int;

    public function canonicalId(): int;

    public function attempt(): int;

    public function actor(): ActorContext;

    public function idempotencyKey(): string;

    public function heartbeat(): void;

    /** @param callable(Asset): void $mutator */
    public function save(callable $mutator): void;
}
