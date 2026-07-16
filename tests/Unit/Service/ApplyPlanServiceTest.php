<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Service\ApplyPlanService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(ApplyPlanService::class)]
#[CoversClass(ApplyPlan::class)]
#[CoversClass(ApplyPlanTarget::class)]
final class ApplyPlanServiceTest extends TestCase
{
    private const string SECRET = 'test-apply-plan-secret';

    #[Test]
    public function equivalentCanonicalPlansVerifyRegardlessOfMapAndTargetOrder(): void
    {
        $service = $this->service();
        $issuedPlan = $this->plan(
            request: ['filter' => ['type' => 'image', 'extensions' => ['jpg', 'png']], 'strategy' => 'safe'],
            config: ['rules' => ['enabled' => true, 'priority' => 10], 'version' => 2],
            targets: [new ApplyPlanTarget('asset:2', 'version:8'), new ApplyPlanTarget('asset:1', 'version:4')],
        );
        $equivalentPlan = $this->plan(
            request: ['strategy' => 'safe', 'filter' => ['extensions' => ['jpg', 'png'], 'type' => 'image']],
            config: ['version' => 2, 'rules' => ['priority' => 10, 'enabled' => true]],
            targets: [new ApplyPlanTarget('asset:1', 'version:4'), new ApplyPlanTarget('asset:2', 'version:8')],
        );

        $token = $service->issue($issuedPlan);

        self::assertSame(ApplyPlanStatus::Valid, $service->verify($token, $equivalentPlan));
    }

    #[Test]
    public function tamperedAndMalformedTokensAreRejectedAsMalformed(): void
    {
        $service = $this->service();
        $plan = $this->plan();
        $token = $service->issue($plan);

        self::assertSame(ApplyPlanStatus::Malformed, $service->verify('not-a-token', $plan));
        self::assertSame(ApplyPlanStatus::Malformed, $service->verify($token . 'x', $plan));
    }

    #[Test]
    public function expiredTokenIsRejectedAsStale(): void
    {
        $now = 1_000;
        $service = $this->service($now, ttlSeconds: 60);
        $plan = $this->plan();
        $token = $service->issue($plan);

        $now = 1_060;

        self::assertSame(ApplyPlanStatus::Stale, $service->verify($token, $plan));
        self::assertSame(ApplyPlanStatus::Stale, $service->claim($token, $plan));
    }

    #[Test]
    public function actorMismatchIsStale(): void
    {
        $service = $this->service();
        $token = $service->issue($this->plan(actor: ActorContext::user(7)));

        self::assertSame(
            ApplyPlanStatus::Stale,
            $service->verify($token, $this->plan(actor: ActorContext::user(8))),
        );
        self::assertSame(
            ApplyPlanStatus::Stale,
            $service->verify($token, $this->plan(actor: ActorContext::system())),
        );
    }

    #[Test]
    public function kindMismatchIsStale(): void
    {
        $service = $this->service();
        $token = $service->issue($this->plan(kind: 'duplicate-merge'));

        self::assertSame(
            ApplyPlanStatus::Stale,
            $service->verify($token, $this->plan(kind: 'integrity-heal')),
        );
    }

    #[Test]
    public function requestMismatchIsStale(): void
    {
        $service = $this->service();
        $token = $service->issue($this->plan(request: ['strategy' => 'safe']));

        self::assertSame(
            ApplyPlanStatus::Stale,
            $service->verify($token, $this->plan(request: ['strategy' => 'delete'])),
        );
    }

    #[Test]
    public function requestListOrderMismatchIsStale(): void
    {
        $service = $this->service();
        $token = $service->issue($this->plan(request: ['assetIds' => [1, 2]]));

        self::assertSame(
            ApplyPlanStatus::Stale,
            $service->verify($token, $this->plan(request: ['assetIds' => [2, 1]])),
        );
    }

    #[Test]
    public function configMismatchIsStale(): void
    {
        $service = $this->service();
        $token = $service->issue($this->plan(config: ['version' => 1]));

        self::assertSame(
            ApplyPlanStatus::Stale,
            $service->verify($token, $this->plan(config: ['version' => 2])),
        );
    }

    #[Test]
    public function targetIdentityOrFingerprintMismatchIsStale(): void
    {
        $service = $this->service();
        $token = $service->issue($this->plan(targets: [new ApplyPlanTarget('asset:1', 'version:4')]));

        self::assertSame(
            ApplyPlanStatus::Stale,
            $service->verify($token, $this->plan(targets: [new ApplyPlanTarget('asset:2', 'version:4')])),
        );
        self::assertSame(
            ApplyPlanStatus::Stale,
            $service->verify($token, $this->plan(targets: [new ApplyPlanTarget('asset:1', 'version:5')])),
        );
    }

    #[Test]
    public function sharedStoresAllowOnlyOneServiceToClaimAToken(): void
    {
        $cache = new ArrayAdapter();
        $lockStore = new InMemoryStore();
        $first = $this->service(cache: $cache, lockStore: $lockStore);
        $second = $this->service(cache: $cache, lockStore: $lockStore);
        $plan = $this->plan();
        $token = $first->issue($plan);

        self::assertSame(ApplyPlanStatus::Claimed, $first->claim($token, $plan));
        self::assertSame(ApplyPlanStatus::AlreadyClaimed, $second->claim($token, $plan));
        self::assertSame(ApplyPlanStatus::Valid, $second->verify($token, $plan));
    }

    #[Test]
    public function concurrentClaimCannotEnterWhileTheTokenClaimLockIsHeld(): void
    {
        $cache = new ArrayAdapter();
        $lockStore = new InMemoryStore();
        $lockFactory = new LockFactory($lockStore);
        $service = $this->service(cache: $cache, lockStore: $lockStore);
        $plan = $this->plan();
        $token = $service->issue($plan);
        $claimLock = $lockFactory->createLock('asset_pilot_apply_plan_claim_' . hash('sha256', $token), 10.0);
        self::assertTrue($claimLock->acquire());

        try {
            self::assertSame(ApplyPlanStatus::AlreadyClaimed, $service->claim($token, $plan));
        } finally {
            $claimLock->release();
        }

        self::assertSame(ApplyPlanStatus::Claimed, $service->claim($token, $plan));
    }

    #[Test]
    public function planRejectsAnEmptyTargetSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->plan(targets: []);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $config
     * @param list<ApplyPlanTarget>|null $targets
     */
    private function plan(
        string $kind = 'duplicate-merge',
        ?ActorContext $actor = null,
        array $request = ['strategy' => 'safe'],
        array $config = ['version' => 1],
        ?array $targets = null,
    ): ApplyPlan {
        return new ApplyPlan(
            kind: $kind,
            actor: $actor ?? ActorContext::user(7),
            request: $request,
            config: $config,
            targets: $targets ?? [new ApplyPlanTarget('asset:1', 'version:4')],
        );
    }

    private function service(
        int &$now = 1_000,
        int $ttlSeconds = 300,
        ?ArrayAdapter $cache = null,
        ?InMemoryStore $lockStore = null,
    ): ApplyPlanService {
        return new ApplyPlanService(
            self::SECRET,
            $cache ?? new ArrayAdapter(),
            new LockFactory($lockStore ?? new InMemoryStore()),
            ttlSeconds: $ttlSeconds,
            clock: static function () use (&$now): int {
                return $now;
            },
        );
    }
}
