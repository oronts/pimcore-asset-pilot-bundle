<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Model\ApplyPlan;

interface ApplyPlanServiceInterface
{
    public function issue(ApplyPlan $plan): string;

    public function verify(string $token, ApplyPlan $plan): ApplyPlanStatus;

    public function claim(string $token, ApplyPlan $plan): ApplyPlanStatus;
}
