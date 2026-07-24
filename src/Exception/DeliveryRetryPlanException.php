<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Exception;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;

class DeliveryRetryPlanException extends \RuntimeException
{
    public function __construct(public readonly ApplyPlanStatus $status)
    {
        parent::__construct(match ($status) {
            ApplyPlanStatus::Malformed => 'The delivery retry plan token is malformed.',
            ApplyPlanStatus::Stale => 'The dead delivery scope changed or the plan token expired. Preview again.',
            ApplyPlanStatus::AlreadyClaimed => 'The delivery retry plan token was already used.',
            ApplyPlanStatus::Valid, ApplyPlanStatus::Claimed => 'The delivery retry plan token could not be claimed.',
        });
    }
}
