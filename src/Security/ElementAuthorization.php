<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Security;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Pimcore\Model\Element\AbstractElement;

class ElementAuthorization
{
    public function __construct(
        private readonly ActorContextStore $actors,
        private readonly ActorContextProvider $provider,
    ) {}

    public function currentActor(): ActorContext
    {
        return $this->actors->current();
    }

    public function isAllowed(AbstractElement $element, string $permission, ?ActorContext $actor = null): bool
    {
        $actor ??= $this->actors->current();
        if ($actor->type === ActorType::System) {
            return true;
        }

        $user = $this->provider->resolveUser($actor);

        return $user !== null && $element->isAllowed($permission, $user);
    }

    public function hasGlobalPermission(string $permission, ?ActorContext $actor = null): bool
    {
        $actor ??= $this->actors->current();
        if ($actor->type === ActorType::System) {
            return true;
        }

        return $this->provider->resolveUser($actor)?->isAllowed($permission) ?? false;
    }
}
