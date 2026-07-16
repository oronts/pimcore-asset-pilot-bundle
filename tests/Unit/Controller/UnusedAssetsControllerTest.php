<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\UnusedAssetsController;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetMutationFingerprintService;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use Oronts\AssetPilotBundle\Service\StorageTrendService;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(UnusedAssetsController::class)]
final class UnusedAssetsControllerTest extends TestCase
{
    #[Test]
    public function listRejectsAnInvalidDateAsBadRequest(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::never())->method('findUnused');

        $response = $this->controller($finder)->list(Request::create('/?before=not-a-date'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(['error' => 'Invalid before date filter.'], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function exportRejectsAnInvalidDateBeforeCreatingAStream(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::never())->method('findUnused');

        $response = $this->controller($finder)->export(Request::create('/?after=invalid'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(['error' => 'Invalid after date filter.'], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function listRejectsAnInvalidConfidenceWithoutWideningTheQuery(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::never())->method('findUnused');

        $response = $this->controller($finder)->list(Request::create('/?confidence=protectd'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(['error' => 'Invalid confidence filter.'], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function exportRejectsAnInvalidConfidenceBeforeCreatingAStream(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::never())->method('findUnused');

        $response = $this->controller($finder)->export(Request::create('/?confidence=unknown'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    #[Test]
    public function userStatsNeverUseTheGlobalMaintenanceSnapshot(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::once())->method('getUnusedStatsCached')->willReturn([
            'totalCount' => 1,
            'totalSize' => 10,
            'totalSizeFormatted' => '10 B',
            'byType' => [],
        ]);
        $trends = $this->createMock(StorageTrendService::class);
        $trends->expects(self::never())->method('latestUnusedStats');

        $response = $this->controller($finder, $trends)->stats();

        self::assertSame(1, json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['totalCount']);
    }

    #[Test]
    public function deleteDryRunReturnsASignedExactPlanWithoutMutating(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::exactly(2))->method('previewMutation')->willReturnMap([
            [2, null],
            [1, 'Asset is locked'],
        ]);
        $finder->expects(self::never())->method('deleteAssets');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('issue')->with(self::callback(function (ApplyPlan $plan): bool {
            self::assertSame('unused-delete', $plan->kind);
            self::assertSame(['assetIds' => [2, 1]], $plan->request);

            return true;
        }))->willReturn('signed-plan');
        $fingerprints = $this->fingerprints([
            new ApplyPlanTarget('asset:2', 'fp-2'),
            new ApplyPlanTarget('asset:1', 'fp-1'),
        ]);

        $response = $this->controller($finder, plans: $plans, fingerprints: $fingerprints)->bulkDelete(
            $this->jsonRequest(['assetIds' => [2, 1], 'dryRun' => true]),
        );
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($body['dryRun']);
        self::assertSame('signed-plan', $body['planToken']);
        self::assertSame(1, $body['eligible']);
        self::assertSame(0, $body['deleted']);
        self::assertSame('Asset is locked', $body['errors'][1]);
    }

    #[Test]
    public function dryRunRejectsAPlanWhenAnAssetChangesDuringPreview(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::once())->method('previewMutation')->with(1)->willReturn(null);
        $finder->expects(self::never())->method('deleteAssets');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::never())->method('issue');
        $fingerprints = $this->createMock(AssetMutationFingerprintService::class);
        $fingerprints->expects(self::exactly(2))->method('reset');
        $fingerprints->method('planConfig')->willReturn([
            'version' => 1,
            'lockProperty' => 'asset_pilot_locked',
            'contentVerification' => true,
        ]);
        $fingerprints->expects(self::exactly(2))->method('targets')->willReturnOnConsecutiveCalls(
            [new ApplyPlanTarget('asset:1', 'before')],
            [new ApplyPlanTarget('asset:1', 'after')],
        );

        $response = $this->controller($finder, plans: $plans, fingerprints: $fingerprints)->bulkDelete(
            $this->jsonRequest(['assetIds' => [1], 'dryRun' => true]),
        );

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertStringContainsString('Preview again', (string) $response->getContent());
    }

    #[Test]
    public function mutationRequiresAFreshPreviewToken(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::never())->method('deleteAssets');

        $response = $this->controller($finder)->bulkDelete($this->jsonRequest(['assetIds' => [1]]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('planToken', (string) $response->getContent());
    }

    #[Test]
    public function staleMovePlanIsRejectedBeforeMutation(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::never())->method('moveAssets');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->method('claim')->willReturn(ApplyPlanStatus::Stale);

        $response = $this->controller(
            $finder,
            plans: $plans,
            fingerprints: $this->fingerprints([new ApplyPlanTarget('asset:1', 'fp-1')]),
        )->bulkMove($this->jsonRequest([
            'assetIds' => [1],
            'targetFolder' => '/Archive',
            'planToken' => 'stale-plan',
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    #[Test]
    public function claimedMovePlanPassesExpectedFingerprintsToTheLockedService(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::once())
            ->method('moveAssets')
            ->with([1], '/Archive', ['asset:1' => 'fp-1'])
            ->willReturn(['moved' => 1, 'failed' => 0, 'errors' => [], 'observerWarnings' => []]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->method('claim')->willReturn(ApplyPlanStatus::Claimed);

        $response = $this->controller(
            $finder,
            plans: $plans,
            fingerprints: $this->fingerprints([new ApplyPlanTarget('asset:1', 'fp-1')]),
        )->bulkMove($this->jsonRequest([
            'assetIds' => [1],
            'targetFolder' => '/Archive',
            'planToken' => 'fresh-plan',
        ]));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertFalse($body['dryRun']);
        self::assertNull($body['planToken']);
        self::assertSame(1, $body['eligible']);
    }

    #[Test]
    public function quarantineDryRunIssuesAPlanWithoutMutating(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $quarantine = $this->createMock(QuarantineService::class);
        $quarantine->expects(self::once())->method('previewQuarantine')->with(5)->willReturn(null);
        $quarantine->expects(self::never())->method('quarantine');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('issue')->with(self::callback(function (ApplyPlan $plan): bool {
            self::assertSame('unused-quarantine', $plan->kind);
            self::assertSame(['assetIds' => [5]], $plan->request);

            return true;
        }))->willReturn('quarantine-plan');

        $response = $this->controller(
            $finder,
            plans: $plans,
            fingerprints: $this->fingerprints([new ApplyPlanTarget('asset:5', 'fp-5')]),
            quarantine: $quarantine,
        )->bulkQuarantine($this->jsonRequest(['assetIds' => [5], 'dryRun' => true]));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $body['quarantined']);
        self::assertSame(1, $body['eligible']);
        self::assertSame('quarantine-plan', $body['planToken']);
        self::assertSame([], $body['observerWarnings']);
    }

    private function controller(
        UnusedAssetFinderInterface $finder,
        ?StorageTrendService $trends = null,
        ?ApplyPlanServiceInterface $plans = null,
        ?AssetMutationFingerprintService $fingerprints = null,
        ?QuarantineService $quarantine = null,
    ): UnusedAssetsController {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));

        return new UnusedAssetsController(
            $finder,
            $quarantine ?? $this->createMock(QuarantineService::class),
            new NullLogger(),
            $trends ?? $this->createMock(StorageTrendService::class),
            $authorization,
            $plans ?? $this->createMock(ApplyPlanServiceInterface::class),
            $fingerprints ?? $this->createMock(AssetMutationFingerprintService::class),
        );
    }

    /** @param list<ApplyPlanTarget> $targets */
    private function fingerprints(array $targets): AssetMutationFingerprintService
    {
        $fingerprints = $this->createMock(AssetMutationFingerprintService::class);
        $fingerprints->method('planConfig')->willReturn(['version' => 1, 'lockProperty' => 'asset_pilot_locked', 'contentVerification' => true]);
        $fingerprints->method('targets')->willReturn($targets);

        return $fingerprints;
    }

    /** @param array<string, mixed> $body */
    private function jsonRequest(array $body): Request
    {
        return Request::create('/', 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
    }
}
