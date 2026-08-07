<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Service\ApplyPlanClaimStoreInterface;
use Oronts\AssetPilotBundle\Service\ApplyPlanService;
use Oronts\AssetPilotBundle\Tests\Support\InMemoryApplyPlanClaimStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $claims = $this->claims();
        $first = $this->service(claims: $claims);
        $second = $this->service(claims: $claims);
        $plan = $this->plan();
        $token = $first->issue($plan);

        self::assertSame(ApplyPlanStatus::Claimed, $first->claim($token, $plan));
        self::assertSame(ApplyPlanStatus::AlreadyClaimed, $second->claim($token, $plan));
        self::assertSame(ApplyPlanStatus::Valid, $second->verify($token, $plan));
    }

    #[Test]
    public function storeConflictIsReportedAsAlreadyClaimed(): void
    {
        $claims = $this->createMock(ApplyPlanClaimStoreInterface::class);
        $claims->expects(self::once())
            ->method('claim')
            ->with(
                self::matchesRegularExpression('/^[a-f0-9]{64}$/D'),
                self::callback(static fn (\DateTimeImmutable $value): bool => $value->getTimestamp() === 1_000),
                self::callback(static fn (\DateTimeImmutable $value): bool => $value->getTimestamp() === 1_300),
            )
            ->willReturn(false);
        $service = $this->service(claims: $claims);
        $plan = $this->plan();
        $token = $service->issue($plan);

        self::assertSame(ApplyPlanStatus::AlreadyClaimed, $service->claim($token, $plan));
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
        ?ApplyPlanClaimStoreInterface $claims = null,
    ): ApplyPlanService {
        return new ApplyPlanService(
            self::SECRET,
            $claims ?? $this->claims(),
            ttlSeconds: $ttlSeconds,
            clock: static function () use (&$now): int {
                return $now;
            },
        );
    }

    private function claims(): ApplyPlanClaimStoreInterface
    {
        return new InMemoryApplyPlanClaimStore();
    }
}
