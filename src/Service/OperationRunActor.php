<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Model\ActorContext;

class OperationRunActor
{
    /** @param array<string, mixed> $run */
    public static function fromRun(array $run): ActorContext
    {
        $type = ActorType::from((string) ($run['actor_type'] ?? ''));
        $userId = $run['actor_user_id'] ?? null;

        return new ActorContext($type, $userId === null ? null : (int) $userId);
    }
}
