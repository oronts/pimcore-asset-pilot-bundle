<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Security;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Pimcore\Model\Element\AbstractElement;

interface ElementAuthorizationInterface
{
    public function currentActor(): ActorContext;

    public function isAllowed(AbstractElement $element, string $permission, ?ActorContext $actor = null): bool;

    public function hasGlobalPermission(string $permission, ?ActorContext $actor = null): bool;
}
