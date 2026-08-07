<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

readonly class DependencyProjectionBatchResult
{
    public function __construct(
        public int $processed,
        public int $failed,
        public bool $completed,
        public DependencyProjectionStatus $status,
    ) {}
}
