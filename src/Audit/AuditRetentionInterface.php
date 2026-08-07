<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Audit;

interface AuditRetentionInterface
{
    public function getRetentionDays(): int;

    public function cleanup(int $retentionDays): int;
}
