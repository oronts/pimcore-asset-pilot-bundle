<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\LoopGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(LoopGuard::class)]
final class DuplicateMergeLockTest extends TestCase
{
    #[Test]
    public function referrerLocksShareAssetAndObjectResourcesWithExistingMutations(): void
    {
        $store = new InMemoryStore();
        $owner = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        $worker = new LoopGuard(new ArrayAdapter(), new LockFactory($store));

        self::assertTrue($owner->acquireAsset(9));
        self::assertFalse($worker->acquireReferrer('asset', 9));
        $owner->releaseAsset(9);
        self::assertTrue($worker->acquireReferrer('asset', 9));
        $worker->releaseReferrer('asset', 9);

        self::assertTrue($owner->acquireObject(42));
        self::assertFalse($worker->acquireReferrer('object', 42));
        $owner->releaseObject(42);
        self::assertTrue($worker->acquireReferrer('object', 42));
        $worker->releaseReferrer('object', 42);
    }

    #[Test]
    public function documentReferrerLockIsExclusiveAndReleasable(): void
    {
        $store = new InMemoryStore();
        $owner = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        $worker = new LoopGuard(new ArrayAdapter(), new LockFactory($store));

        self::assertTrue($owner->acquireReferrer('document', 12));
        self::assertFalse($worker->acquireReferrer('document', 12));
        $owner->releaseReferrer('document', 12);
        self::assertTrue($worker->acquireReferrer('document', 12));
    }
}
