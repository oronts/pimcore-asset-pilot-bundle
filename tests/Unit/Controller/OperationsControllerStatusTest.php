<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatter;
use Oronts\AssetPilotBundle\Api\Serialization\OperationResponseAssembler;
use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Controller\Api\OperationsController;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetOrganizerInterface;
use Oronts\AssetPilotBundle\Service\AssetReorganizerInterface;
use Oronts\AssetPilotBundle\Service\FailureReplayServiceInterface;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrainInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\OrganizeRunDispatchCoordinator;
use Oronts\AssetPilotBundle\Service\Query\VisibleObjectSelectorInterface;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
use Oronts\AssetPilotBundle\Service\RunItemLease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(OperationsController::class)]
final class OperationsControllerStatusTest extends TestCase
{
    #[Test]
    public function statusEmitsRecentOperationTimestampsAsRfc3339Utc(): void
    {
        $audit = $this->createMock(AuditQueryInterface::class);
        $audit->method('getStats')->willReturn([]);
        $audit->method('getRecent')->willReturn([
            ['id' => 4, 'status' => 'completed', 'created_at' => '2026-07-15 10:00:00', 'updated_at' => '2026-07-15 10:00:05', 'committed_at' => '2026-07-15 10:00:06'],
        ]);

        $response = $this->controller($audit)->status();
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('2026-07-15T10:00:00+00:00', $body['recentOperations'][0]['created_at']);
        self::assertSame('2026-07-15T10:00:05+00:00', $body['recentOperations'][0]['updated_at']);
        self::assertSame('2026-07-15T10:00:06+00:00', $body['recentOperations'][0]['committed_at']);
    }

    private function controller(AuditQueryInterface $audit): OperationsController
    {
        return new OperationsController(
            $this->createMock(AssetOrganizerInterface::class),
            $this->createMock(OrganizeDispatcherInterface::class),
            $audit,
            $this->createMock(RuleEngineInterface::class),
            $this->createMock(AssetFieldExtractorInterface::class),
            $this->createMock(FailureReplayServiceInterface::class),
            $this->createMock(AssetReorganizerInterface::class),
            $this->createMock(ElementAuthorizationInterface::class),
            $this->createMock(OperationRunStoreInterface::class),
            $this->createMock(ApplyPlanServiceInterface::class),
            $this->createMock(OrganizePlanFingerprint::class),
            $this->createMock(ReviewedObjectOperationServiceInterface::class),
            new NullLogger(),
            new ApiDateFormatter(),
            $this->createMock(RunItemLease::class),
            new OperationResponseAssembler($this->createMock(UrlGeneratorInterface::class)),
            new OrganizeRunDispatchCoordinator($this->createMock(OrganizeDispatcherInterface::class), $this->createMock(OperationRunStoreInterface::class), new NullLogger()),
            $this->createMock(VisibleObjectSelectorInterface::class),
            $this->createMock(ObjectSaveDrainInterface::class),
        );
    }
}
