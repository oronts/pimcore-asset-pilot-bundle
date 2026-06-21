<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

/**
 * Outcome of replaying failed operations. `candidates` is the distinct failed objects considered;
 * `organized` ran synchronously, `dispatched` were queued, `skipped` no longer exist, `failed`
 * threw on the synchronous re-organize.
 */
readonly class ReplayResult
{
    public function __construct(
        public int $candidates,
        public int $organized,
        public int $dispatched,
        public int $skipped,
        public int $failed,
    ) {}
}
