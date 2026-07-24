<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Exception;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;

class OperationRecoveryPlanException extends \RuntimeException
{
    public function __construct(public readonly ApplyPlanStatus $status)
    {
        parent::__construct(match ($status) {
            ApplyPlanStatus::Malformed => 'The recovery plan token is malformed.',
            ApplyPlanStatus::Stale => 'The recovery scope changed or the plan token expired. Preview again.',
            ApplyPlanStatus::AlreadyClaimed => 'The recovery plan token was already used.',
            ApplyPlanStatus::Valid, ApplyPlanStatus::Claimed => 'The recovery plan token could not be claimed.',
        });
    }
}
