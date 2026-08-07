<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Security;

use Oronts\AssetPilotBundle\Model\ActorContext;

class ActorContextStore
{
    private ?ActorContext $scopedActor = null;

    public function __construct(
        private readonly ActorContextProvider $provider,
    ) {}

    public function current(): ActorContext
    {
        return $this->scopedActor ?? $this->provider->current();
    }

    public function runAs(ActorContext $actor, callable $operation): mixed
    {
        $previous = $this->scopedActor;
        $this->scopedActor = $actor;

        try {
            return $operation();
        } finally {
            $this->scopedActor = $previous;
        }
    }
}
