<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface OperationRunRetentionInterface
{
    public function prune(): int;
}
