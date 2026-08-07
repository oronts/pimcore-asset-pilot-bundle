<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\TriggerType;

readonly class AutomaticOrganizeIntent
{
    public function __construct(
        public int $objectId,
        public string $runId,
        public TriggerType $trigger,
        public ActorContext $actor,
        public bool $dirty,
    ) {}
}
