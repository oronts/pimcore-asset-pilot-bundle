<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Service\ZipDownloadTokenStore;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ZipDownloadTokenStore::class)]
final class ZipDownloadTokenStoreTest extends TestCase
{
    #[Test]
    public function issuedPlanCanBeClaimedOnlyOnceByTheSameActor(): void
    {
        $store = new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $token = $store->issue([3, 7], new ZipBuildOptions('folder', 'web'), ActorContext::user(42));

        $plan = $store->claim($token, ActorContext::user(42));

        self::assertNotNull($plan);
        self::assertSame([3, 7], $plan->assetIds);
        self::assertSame('folder', $plan->options->strategy);
        self::assertSame('web', $plan->options->thumbnail);
        self::assertNull($store->claim($token, ActorContext::user(42)));
    }

    #[Test]
    public function tokenIsBoundToThePreparingActor(): void
    {
        $store = new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $token = $store->issue([3], new ZipBuildOptions(), ActorContext::user(42));

        self::assertNull($store->claim($token, ActorContext::user(43)));
        self::assertNull($store->claim($token, ActorContext::system()));
        self::assertNotNull($store->claim($token, ActorContext::user(42)));
    }

    #[Test]
    public function malformedAndUnknownTokensAreRejected(): void
    {
        $store = new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()));

        self::assertNull($store->claim('invalid', ActorContext::user(42)));
        self::assertNull($store->claim(str_repeat('a', 43), ActorContext::user(42)));
    }

    #[Test]
    public function tokenLifetimeMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ZipDownloadTokenStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), 0);
    }
}
