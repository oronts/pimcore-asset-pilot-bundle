<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Message;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\TriggerType;

readonly class OrganizeAssetsMessage
{
    public function __construct(
        public int $objectId,
        public TriggerType $triggerType,
        public int $dispatchedAt = 0,
        public ActorType $actorType = ActorType::Anonymous,
        public ?int $actorUserId = null,
        public ?string $runId = null,
        public ?string $expectedFingerprint = null,
    ) {}
}
