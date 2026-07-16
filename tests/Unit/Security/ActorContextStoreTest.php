<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Security;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ActorContextStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorContextStore::class)]
class ActorContextStoreTest extends TestCase
{
    #[Test]
    public function restoresPreviousActorAfterFailure(): void
    {
        $provider = $this->createMock(ActorContextProvider::class);
        $provider->method('current')->willReturn(ActorContext::system());
        $store = new ActorContextStore($provider);

        try {
            $store->runAs(ActorContext::user(8), static function () use ($store): void {
                self::assertSame(8, $store->current()->userId);
                throw new \RuntimeException('failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(ActorContext::system()->type, $store->current()->type);
    }
}
