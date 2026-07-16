<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\FoldersController;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\EmptyFolderSweepService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(FoldersController::class)]
final class FoldersControllerTest extends TestCase
{
    #[Test]
    public function dryRunIssuesAPlanForAStablePreview(): void
    {
        $plan = $this->plan();
        $sweep = $this->createMock(EmptyFolderSweepService::class);
        $sweep->expects(self::exactly(2))->method('createDeletePlan')->with([5])->willReturn($plan);
        $sweep->expects(self::once())->method('previewDelete')->with([5])->willReturn([
            'deleted' => 0,
            'eligible' => 1,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('issue')->with($plan)->willReturn('signed-plan');

        $response = $this->controller($sweep, $plans)->deleteEmpty($this->request([
            'ids' => [5],
            'dryRun' => true,
        ]));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($body['dryRun']);
        self::assertSame('signed-plan', $body['planToken']);
        self::assertSame(1, $body['eligible']);
    }

    #[Test]
    public function applyRequiresAFreshPlanToken(): void
    {
        $response = $this->controller(
            $this->createMock(EmptyFolderSweepService::class),
            $this->createMock(ApplyPlanServiceInterface::class),
        )->deleteEmpty($this->request(['ids' => [5]]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    #[Test]
    public function appliesTheClaimedPlanWithItsExactFingerprints(): void
    {
        $plan = $this->plan();
        $sweep = $this->createMock(EmptyFolderSweepService::class);
        $sweep->expects(self::once())->method('createDeletePlan')->with([5])->willReturn($plan);
        $sweep->expects(self::once())->method('deleteEmpty')->with([5], ['folder:5' => 'fingerprint-5'])->willReturn([
            'deleted' => 1,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ]);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->with('signed-plan', $plan)->willReturn(ApplyPlanStatus::Claimed);

        $response = $this->controller($sweep, $plans)->deleteEmpty($this->request([
            'ids' => [5],
            'planToken' => 'signed-plan',
        ]));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(1, $body['deleted']);
        self::assertFalse($body['dryRun']);
        self::assertNull($body['planToken']);
    }

    private function controller(EmptyFolderSweepService $sweep, ApplyPlanServiceInterface $plans): FoldersController
    {
        return new FoldersController($sweep, $plans, new NullLogger());
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): Request
    {
        return new Request(content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function plan(): ApplyPlan
    {
        return new ApplyPlan(
            'empty-folder-delete',
            ActorContext::user(7),
            ['folderIds' => [5]],
            ['version' => 1],
            [new ApplyPlanTarget('folder:5', 'fingerprint-5')],
        );
    }
}
