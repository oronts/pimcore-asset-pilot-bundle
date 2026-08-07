<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Security;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\User;
use Pimcore\Security\User\TokenStorageUserResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ActorContextProviderTest extends TestCase
{
    public function testCurrent_WithAuthenticatedUser_ReturnsUserContext(): void
    {
        $user = new User();
        $user->setId(42);
        $resolver = $this->createMock(TokenStorageUserResolver::class);
        $resolver->method('getUser')->willReturn($user);

        $actor = new ActorContextProvider($resolver, new RequestStack())->current();

        self::assertSame(ActorType::User, $actor->type);
        self::assertSame(42, $actor->userId);
    }

    public function testCurrent_WithAnonymousHttpRequest_ReturnsAnonymousContext(): void
    {
        $resolver = $this->createMock(TokenStorageUserResolver::class);
        $resolver->method('getUser')->willReturn(null);
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $actor = new ActorContextProvider($resolver, $requestStack)->current();

        self::assertSame(ActorType::Anonymous, $actor->type);
        self::assertNull($actor->userId);
    }

    public function testCurrent_WithoutHttpRequest_ReturnsSystemContext(): void
    {
        $resolver = $this->createMock(TokenStorageUserResolver::class);
        $resolver->method('getUser')->willReturn(null);

        $actor = new ActorContextProvider($resolver, new RequestStack())->current();

        self::assertSame(ActorType::System, $actor->type);
        self::assertNull($actor->userId);
    }

    public function testResolveUser_WithActiveUser_ReturnsUser(): void
    {
        $user = new User();
        $user->setActive(true);
        $provider = $this->providerLoading($user);

        self::assertSame($user, $provider->resolveUser(ActorContext::user(42)));
    }

    public function testResolveUser_WithInactiveUser_ReturnsNull(): void
    {
        $user = new User();
        $user->setActive(false);
        $provider = $this->providerLoading($user);

        self::assertNull($provider->resolveUser(ActorContext::user(42)));
    }

    public function testResolveUser_WithServicePrincipal_ReturnsNullWithoutLoading(): void
    {
        $provider = $this->providerLoading(null);

        self::assertNull($provider->resolveUser(ActorContext::system()));
    }

    private function providerLoading(?User $user): ActorContextProvider
    {
        $resolver = $this->createMock(TokenStorageUserResolver::class);

        return new class ($resolver, new RequestStack(), $user) extends ActorContextProvider {
            public function __construct(
                TokenStorageUserResolver $resolver,
                RequestStack $requestStack,
                private readonly ?User $loadedUser,
            ) {
                parent::__construct($resolver, $requestStack);
            }

            protected function loadUser(int $userId): ?User
            {
                TestCase::assertSame(42, $userId);

                return $this->loadedUser;
            }
        };
    }
}
