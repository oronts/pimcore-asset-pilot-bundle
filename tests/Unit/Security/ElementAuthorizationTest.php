<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Security;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Element\AbstractElement;
use Pimcore\Model\User;

#[CoversClass(ElementAuthorization::class)]
class ElementAuthorizationTest extends TestCase
{
    #[Test]
    public function systemActorIsAnExplicitServicePrincipal(): void
    {
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->expects(self::never())->method('resolveUser');
        $provider->method('current')->willReturn(ActorContext::system());
        $authorization = new ElementAuthorization(new ActorContextStore($provider), $provider);

        self::assertTrue($authorization->isAllowed($this->createMock(AbstractElement::class), 'publish'));
    }

    #[Test]
    public function anonymousActorFailsClosed(): void
    {
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('resolveUser')->willReturn(null);
        $provider->method('current')->willReturn(ActorContext::anonymous());
        $authorization = new ElementAuthorization(new ActorContextStore($provider), $provider);

        self::assertFalse($authorization->isAllowed($this->createMock(AbstractElement::class), 'view'));
    }

    #[Test]
    public function userActorUsesNativeElementPermission(): void
    {
        $user = new User();
        $user->setId(9);
        $user->setActive(true);
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('resolveUser')->with(ActorContext::user(9))->willReturn($user);
        $provider->method('current')->willReturn(ActorContext::user(9));
        $element = $this->createMock(AbstractElement::class);
        $element->expects(self::once())->method('isAllowed')->with('publish', $user)->willReturn(true);

        self::assertTrue((new ElementAuthorization(new ActorContextStore($provider), $provider))->isAllowed($element, 'publish'));
    }
}
