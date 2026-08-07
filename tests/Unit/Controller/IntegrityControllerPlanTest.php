<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\IntegrityController;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\HealOutcome;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\HealResult;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetIntegrityService;
use Oronts\AssetPilotBundle\Service\IntegrityHealFingerprintService;
use Oronts\AssetPilotBundle\Service\IntegrityHealHistoryService;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(IntegrityController::class)]
final class IntegrityControllerPlanTest extends TestCase
{
    #[Test]
    public function dryRunIssuesAnActorBoundPlanForSortedIdsAndExactResults(): void
    {
        $actor = ActorContext::user(42);
        $preview = $this->previewResults();
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->expects(self::exactly(2))->method('previewById')->willReturnCallback(
            static fn (int $assetId): HealResult => $preview[$assetId],
        );
        $fingerprints = $this->fingerprints([
            new ApplyPlanTarget('asset:2', 'target-2'),
            new ApplyPlanTarget('asset:9', 'target-9'),
        ]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('issue')->with(self::callback(
            static fn (ApplyPlan $plan): bool => $plan->kind === 'integrity-heal'
                && $plan->actor === $actor
                && $plan->request === ['assetIds' => [2, 9]]
                && $plan->config === ['version' => 1]
                && array_column($plan->targets, 'fingerprint') === ['target-2', 'target-9'],
        ))->willReturn('v1.preview');

        $response = $this->controller($healer, $plans, $fingerprints, $actor)->heal($this->request([
            'ids' => [9, 2],
            'dryRun' => true,
        ]));
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['dryRun']);
        self::assertSame('v1.preview', $body['planToken']);
        self::assertSame([2, 9], array_column($body['results'], 'assetId'));
        self::assertSame(['healed', 'already_renderable'], array_column($body['results'], 'outcome'));
    }

    #[Test]
    public function applyRequiresAPlanTokenAndStrictBooleanDryRun(): void
    {
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->expects(self::never())->method('previewById');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::never())->method('claim');
        $controller = $this->controller(
            $healer,
            $plans,
            $this->createMock(IntegrityHealFingerprintService::class),
            ActorContext::user(42),
        );

        self::assertSame(400, $controller->heal($this->request(['ids' => [2]]))->getStatusCode());
        self::assertSame(400, $controller->heal($this->request(['ids' => [2], 'dryRun' => 'true']))->getStatusCode());
    }

    #[Test]
    public function previewSnapshotRaceReturnsConflictWithoutIssuingAToken(): void
    {
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->method('previewById')->willReturn(new HealResult(HealOutcome::Healed, 'image', 12, dryRun: true));
        $fingerprints = $this->createMock(IntegrityHealFingerprintService::class);
        $fingerprints->expects(self::exactly(2))->method('fingerprintMap')->willReturnOnConsecutiveCalls(
            ['asset:2' => 'before'],
            ['asset:2' => 'after'],
        );
        $fingerprints->expects(self::never())->method('targets');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::never())->method('issue');

        $response = $this->controller($healer, $plans, $fingerprints, ActorContext::user(42))->heal($this->request([
            'ids' => [2],
            'dryRun' => true,
        ]));

        self::assertSame(409, $response->getStatusCode());
        self::assertArrayNotHasKey('planToken', $this->body($response));
    }

    #[Test]
    public function applyClaimsThePlanAndPassesEveryTargetToTheBatchHealer(): void
    {
        $preview = $this->previewResults();
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->expects(self::exactly(2))->method('previewById')->willReturnCallback(
            static fn (int $assetId): HealResult => $preview[$assetId],
        );
        $healer->expects(self::once())->method('healPlannedBatch')->with(
            [2, 9],
            ['asset:2' => 'target-2', 'asset:9' => 'target-9'],
        )->willReturn([
            2 => new HealResult(HealOutcome::Healed, 'image', 12),
            9 => new HealResult(HealOutcome::AlreadyRenderable, 'image'),
        ]);
        $fingerprints = $this->fingerprints([
            new ApplyPlanTarget('asset:2', 'target-2'),
            new ApplyPlanTarget('asset:9', 'target-9'),
        ]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->with('v1.preview', self::isInstanceOf(ApplyPlan::class))->willReturn(ApplyPlanStatus::Claimed);

        $response = $this->controller($healer, $plans, $fingerprints, ActorContext::user(42))->heal($this->request([
            'ids' => [9, 2],
            'planToken' => 'v1.preview',
        ]));
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($body['dryRun']);
        self::assertNull($body['planToken']);
        self::assertSame([2, 9], array_column($body['results'], 'assetId'));
    }

    /** @return iterable<string, array{ApplyPlanStatus, int}> */
    public static function rejectedTokenStatuses(): iterable
    {
        yield 'malformed' => [ApplyPlanStatus::Malformed, 400];
        yield 'already claimed' => [ApplyPlanStatus::AlreadyClaimed, 409];
        yield 'stale' => [ApplyPlanStatus::Stale, 409];
    }

    #[Test]
    #[DataProvider('rejectedTokenStatuses')]
    public function rejectedTokensHaveStableHttpStatuses(ApplyPlanStatus $status, int $httpStatus): void
    {
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->method('previewById')->willReturn(new HealResult(HealOutcome::Healed, 'image', 12, dryRun: true));
        $healer->expects(self::never())->method('healPlannedBatch');
        $fingerprints = $this->fingerprints([new ApplyPlanTarget('asset:2', 'target-2')]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->method('claim')->willReturn($status);

        $response = $this->controller($healer, $plans, $fingerprints, ActorContext::user(42))->heal($this->request([
            'ids' => [2],
            'planToken' => 'token',
        ]));

        self::assertSame($httpStatus, $response->getStatusCode());
    }

    /** @param list<ApplyPlanTarget> $targets */
    private function fingerprints(array $targets): IntegrityHealFingerprintService
    {
        $state = [];
        foreach ($targets as $target) {
            $state[$target->id] = 'state-' . $target->id;
        }

        $fingerprints = $this->createMock(IntegrityHealFingerprintService::class);
        $fingerprints->method('fingerprintMap')->willReturn($state);
        $fingerprints->method('planConfig')->willReturn(['version' => 1]);
        $fingerprints->method('targets')->willReturn($targets);

        return $fingerprints;
    }

    /** @return array<int, HealResult> */
    private function previewResults(): array
    {
        return [
            2 => new HealResult(HealOutcome::Healed, 'image', 12, dryRun: true),
            9 => new HealResult(HealOutcome::AlreadyRenderable, 'image', dryRun: true),
        ];
    }

    private function controller(
        VersionRollbackHealer $healer,
        ApplyPlanServiceInterface $plans,
        IntegrityHealFingerprintService $fingerprints,
        ActorContext $actor,
    ): IntegrityController {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);

        return new IntegrityController(
            $this->createMock(AssetIntegrityService::class),
            $healer,
            $this->createMock(IntegrityHealHistoryService::class),
            new NullLogger(),
            $plans,
            $fingerprints,
            $authorization,
        );
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): Request
    {
        return Request::create(
            '/integrity/heal',
            'POST',
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function body(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
