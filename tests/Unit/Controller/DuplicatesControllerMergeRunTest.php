<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\DuplicatesController;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Exception\MergeLeaseLostException;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\MergeOutcome;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetSearchServiceInterface;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(DuplicatesController::class)]
final class DuplicatesControllerMergeRunTest extends TestCase
{
    #[Test]
    public function mergeRejectsMalformedCanonicalIdAndNonBooleanDryRun(): void
    {
        $controller = $this->controller($this->createMock(DuplicateMergeService::class));

        $badId = $controller->merge($this->jsonRequest(['checksum' => 'abc', 'canonicalId' => 'not-an-id']));
        self::assertSame(Response::HTTP_BAD_REQUEST, $badId->getStatusCode());

        $badBool = $controller->merge($this->jsonRequest(['checksum' => 'abc', 'dryRun' => 'false']));
        self::assertSame(Response::HTTP_BAD_REQUEST, $badBool->getStatusCode());
    }

    #[Test]
    public function mergeRejectsNonStringRunIdAndStrategyAtTheEndpoint(): void
    {
        $controller = $this->controller($this->createMock(DuplicateMergeService::class));

        $runId = $controller->merge($this->jsonRequest(['runId' => ['x']]));
        self::assertSame(Response::HTTP_BAD_REQUEST, $runId->getStatusCode());
        self::assertSame('runId must be a string.', json_decode((string) $runId->getContent(), true, 512, JSON_THROW_ON_ERROR)['error']);

        $strategy = $controller->merge($this->jsonRequest(['checksum' => 'abc', 'strategy' => ['x']]));
        self::assertSame(Response::HTTP_BAD_REQUEST, $strategy->getStatusCode());
        self::assertSame('strategy must be a string.', json_decode((string) $strategy->getContent(), true, 512, JSON_THROW_ON_ERROR)['error']);
    }

    #[Test]
    public function dryRunIssuesTheExactPlanAndPreviewsWithoutFingerprints(): void
    {
        $group = new DuplicateGroup('abc', 128, 2, [9, 3]);
        $targets = [
            new ApplyPlanTarget('asset:3', 'fp-3'),
            new ApplyPlanTarget('asset:9', 'fp-9'),
        ];
        $duplicates = $this->createMock(DuplicateDetectionService::class);
        $duplicates->method('groupForChecksum')->with('abc')->willReturn($group);
        $merge = $this->createMock(DuplicateMergeService::class);
        $merge->method('defaultStrategyName')->willReturn('quarantine');
        $merge->expects(self::once())->method('planTargets')->with($group, 3)->willReturn($targets);
        $merge->expects(self::once())->method('preview')->with(
            $group,
            3,
            'quarantine',
        )->willReturn(new MergeOutcome('abc', 3, [
            new CopyDisposition(9, DispositionOutcome::Skipped, 'Would quarantine duplicate'),
        ]));
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('issue')->with(self::callback(
            static fn (ApplyPlan $plan): bool => $plan->kind === OperationRunKind::DuplicateMerge->value
                && $plan->actor == ActorContext::user(7)
                && $plan->request === ['checksum' => 'abc', 'canonicalId' => 3, 'strategy' => 'quarantine']
                && $plan->targets === $targets,
        ))->willReturn('signed-plan');

        $response = $this->controller($merge, $duplicates, $plans)->merge($this->jsonRequest([
            'checksum' => 'abc',
            'dryRun' => true,
        ]));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($body['dryRun']);
        self::assertSame('signed-plan', $body['planToken']);
        self::assertNull($body['runId']);
        self::assertNull($body['statusUrl']);
    }

    #[Test]
    public function applyClaimsThePlanAndPassesEveryExactFingerprint(): void
    {
        $group = new DuplicateGroup('abc', 128, 2, [3, 9]);
        $targets = [
            new ApplyPlanTarget('asset:3', 'fp-3'),
            new ApplyPlanTarget('asset:9', 'fp-9'),
        ];
        $duplicates = $this->createMock(DuplicateDetectionService::class);
        $duplicates->method('groupForChecksum')->with('abc')->willReturn($group);
        $merge = $this->createMock(DuplicateMergeService::class);
        $merge->method('defaultStrategyName')->willReturn('quarantine');
        $merge->method('planTargets')->with($group, 3)->willReturn($targets);
        $merge->expects(self::once())->method('merge')->with(
            $group,
            ['asset:3' => 'fp-3', 'asset:9' => 'fp-9'],
            3,
            'quarantine',
        )->willReturn(new MergeOutcome('abc', 3, [], 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', OperationRunStatus::Queued));
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->with('signed-plan', self::isInstanceOf(ApplyPlan::class))
            ->willReturn(ApplyPlanStatus::Claimed);

        $response = $this->controller($merge, $duplicates, $plans)->merge($this->jsonRequest([
            'checksum' => 'abc',
            'canonicalId' => 3,
            'planToken' => 'signed-plan',
        ]));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertFalse($body['dryRun']);
        self::assertNull($body['planToken']);
        self::assertSame('queued', $body['status']);
        self::assertSame('/configured/operations/runs/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $body['statusUrl']);
    }

    #[Test]
    public function resumesPersistedMergeAndReturnsRunIdentityAndHonestStatus(): void
    {
        $runId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $merge = $this->createMock(DuplicateMergeService::class);
        $merge->expects(self::once())->method('resume')->with($runId)->willReturn(new MergeOutcome(
            'abc',
            3,
            [new CopyDisposition(9, DispositionOutcome::Blocked, 'referrer changed')],
            $runId,
            OperationRunStatus::Blocked,
        ));

        $response = $this->controller($merge)->merge($this->jsonRequest(['runId' => $runId]));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame($runId, $body['runId']);
        self::assertSame('blocked', $body['status']);
        self::assertSame('/configured/operations/runs/' . $runId, $body['statusUrl']);
        self::assertSame('blocked', $body['dispositions'][0]['outcome']);
    }

    #[Test]
    public function applyReturnsResumableRunIdOnFinalizationConflictAndResumeCompletesIt(): void
    {
        $runId = 'cccccccccccccccccccccccccccccccc';
        $group = new DuplicateGroup('abc', 128, 2, [3, 9]);
        $targets = [new ApplyPlanTarget('asset:3', 'fp-3'), new ApplyPlanTarget('asset:9', 'fp-9')];
        $duplicates = $this->createMock(DuplicateDetectionService::class);
        $duplicates->method('groupForChecksum')->with('abc')->willReturn($group);
        $merge = $this->createMock(DuplicateMergeService::class);
        $merge->method('defaultStrategyName')->willReturn('quarantine');
        $merge->method('planTargets')->with($group, 3)->willReturn($targets);
        $merge->method('merge')->willThrowException(MergeLeaseLostException::forRun(
            $runId,
            $runId,
            'The duplicate merge run could not be finalized; retry to complete it.',
        ));
        $merge->method('resume')->with($runId)->willReturn(new MergeOutcome('abc', 3, [], $runId, OperationRunStatus::Completed));
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->method('claim')->willReturn(ApplyPlanStatus::Claimed);
        $controller = $this->controller($merge, $duplicates, $plans);

        // Apply hits a finalization conflict after the plan token is consumed: the 409 must carry the resumable id.
        $apply = $controller->merge($this->jsonRequest([
            'checksum' => 'abc',
            'canonicalId' => 3,
            'planToken' => 'signed-plan',
        ]));
        $applyBody = json_decode((string) $apply->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(Response::HTTP_CONFLICT, $apply->getStatusCode());
        self::assertSame($runId, $applyBody['runId']);
        self::assertSame('/configured/operations/runs/' . $runId, $applyBody['statusUrl']);

        // The caller resumes directly with the returned id -- no consumed plan token, no run-list search.
        $resume = $controller->merge($this->jsonRequest(['runId' => $applyBody['runId']]));
        $resumeBody = json_decode((string) $resume->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(Response::HTTP_OK, $resume->getStatusCode());
        self::assertSame('completed', $resumeBody['status']);
    }

    private function controller(
        DuplicateMergeService $merge,
        ?DuplicateDetectionService $duplicates = null,
        ?ApplyPlanServiceInterface $plans = null,
    ): DuplicatesController {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static function (string $route, array $parameters): string {
            self::assertSame('oronts_asset_pilot_operation_run_get', $route);

            return '/configured/operations/runs/' . $parameters['id'];
        });

        return new DuplicatesController(
            $duplicates ?? $this->createMock(DuplicateDetectionService::class),
            $merge,
            $this->createMock(AssetSearchServiceInterface::class),
            $plans ?? $this->createMock(ApplyPlanServiceInterface::class),
            $authorization,
            $urls,
            new NullLogger(),
        );
    }

    /** @param array<string, mixed> $data */
    private function jsonRequest(array $data): Request
    {
        return Request::create(
            '/duplicates/merge',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}
