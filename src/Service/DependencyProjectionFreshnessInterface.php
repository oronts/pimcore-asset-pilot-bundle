<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\DependencyProjectionStatus;

interface DependencyProjectionFreshnessInterface
{
    public function generation(): int;

    public function status(): DependencyProjectionStatus;

    public function beginRebuild(bool $restart = false): DependencyProjectionStatus;

    public function advanceRebuild(string $sourceType, int $sourceId): void;

    public function completeRebuild(): DependencyProjectionStatus;

    public function failRebuild(string $error): void;
}
