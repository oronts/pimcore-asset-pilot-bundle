<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatter;
use Oronts\AssetPilotBundle\Api\Serialization\OperationResponseAssembler;
use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Controller\Api\OperationsController;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\AssetReorganizer;
use Oronts\AssetPilotBundle\Service\FailureReplayService;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrainInterface;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\OrganizeRunDispatchCoordinator;
use Oronts\AssetPilotBundle\Service\Query\VisibleObjectSelectorInterface;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
use Oronts\AssetPilotBundle\Service\RunItemLease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(OperationsController::class)]
final class OperationsControllerSimulateTest extends TestCase
{
    #[Test]
    public function recordsPreviewedMovesAsATerminalSimulationRun(): void
    {
        $captured = null;
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::once())->method('create')->willReturnCallback(
            function (OperationRunKind $kind, ActorContext $actor, array $items, array $request = [], ?string $retryOf = null, OperationRunStatus $initialStatus = OperationRunStatus::Queued, ?string $runId = null) use (&$captured): string {
                $captured = ['kind' => $kind, 'items' => $items, 'request' => $request, 'status' => $initialStatus];

                return 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';
            },
        );

        $moves = [
            $this->move(12, '/Uploads/a.jpg', '/Photos/a.jpg'),
            $this->move(13, '/Uploads/b.pdf', '/Print/b.pdf'),
        ];
        $response = $this->controller($moves, $runs)->simulate($this->post('{"objectId":42}'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4', $body['runId']);
        self::assertCount(2, $body['operations']);

        self::assertSame(OperationRunKind::Simulation, $captured['kind']);
        self::assertSame(OperationRunStatus::Completed, $captured['status'], 'a simulation is a terminal record, never dispatched');
        self::assertSame(42, $captured['request']['objectId']);
        self::assertCount(2, $captured['items']);
        self::assertSame('asset', $captured['items'][0]['type']);
        self::assertSame(['from' => '/Uploads/a.jpg', 'to' => '/Photos/a.jpg', 'ruleName' => 'images', 'objectId' => 42], $captured['items'][0]['state']);
    }

    #[Test]
    public function recordsNothingWhenNoMovesWouldResult(): void
    {
        $runs = $this->createMock(OperationRunStoreInterface::class);
        $runs->expects(self::never())->method('create');

        $response = $this->controller([], $runs)->simulate($this->post('{"objectId":42}'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertNull($body['runId'], 'nothing to move means nothing is recorded');
        self::assertSame([], $body['operations']);
    }

    private function move(int $assetId, string $from, string $to): MoveOperation
    {
        return new MoveOperation($assetId, $from, $to, 42, 'Product', 'images', OperationStatus::Completed, TriggerType::Api, executionFingerprint: 'fp' . $assetId);
    }

    /** @param list<MoveOperation> $moves */
    private function controller(array $moves, OperationRunStoreInterface $runs): OperationsController
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn($moves);
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);
        $authorization->method('currentActor')->willReturn(ActorContext::user(7));

        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(42);

        $controller = new class (
            $organizer,
            $this->createMock(OrganizeDispatcher::class),
            $this->createMock(AuditQueryInterface::class),
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(FailureReplayService::class),
            $this->createMock(AssetReorganizer::class),
            $authorization,
            $runs,
            $this->createMock(ApplyPlanServiceInterface::class),
            new OrganizePlanFingerprint(),
            $this->createMock(ReviewedObjectOperationServiceInterface::class),
            new NullLogger(),
            new ApiDateFormatter(),
            $this->createMock(RunItemLease::class),
            new OperationResponseAssembler($this->createMock(UrlGeneratorInterface::class)),
            new OrganizeRunDispatchCoordinator($this->createMock(OrganizeDispatcher::class), $this->createMock(OperationRunStoreInterface::class), new NullLogger()),
            $this->createMock(VisibleObjectSelectorInterface::class),
            $this->createMock(ObjectSaveDrainInterface::class),
        ) extends OperationsController {
            public ?AbstractObject $stub = null;

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->stub;
            }
        };
        $controller->stub = $object;

        return $controller;
    }

    private function post(string $body): Request
    {
        return Request::create('/', 'POST', [], [], [], [], $body);
    }
}
