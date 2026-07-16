<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Security;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Pimcore\Model\User;
use Pimcore\Security\User\TokenStorageUserResolver;
use Symfony\Component\HttpFoundation\RequestStack;

class ActorContextProvider
{
    public function __construct(
        private readonly TokenStorageUserResolver $userResolver,
        private readonly RequestStack $requestStack,
    ) {}

    public function current(): ActorContext
    {
        $user = $this->userResolver->getUser();
        if ($user !== null && ($user->getId() ?? 0) > 0) {
            return ActorContext::user((int) $user->getId());
        }

        return $this->requestStack->getCurrentRequest() === null
            ? ActorContext::system()
            : ActorContext::anonymous();
    }

    public function resolveUser(ActorContext $actor): ?User
    {
        if ($actor->userId === null) {
            return null;
        }

        $user = $this->loadUser($actor->userId);

        return $user?->getActive() ? $user : null;
    }

    protected function loadUser(int $userId): ?User
    {
        return User::getById($userId);
    }
}
