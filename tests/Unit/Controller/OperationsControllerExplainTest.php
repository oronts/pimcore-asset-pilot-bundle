<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatter;
use Oronts\AssetPilotBundle\Api\Serialization\OperationResponseAssembler;
use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Controller\Api\OperationsController;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Model\AssetFieldInfo;
use Oronts\AssetPilotBundle\Model\RuleEvaluation;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\AssetReorganizer;
use Oronts\AssetPilotBundle\Service\FailureReplayService;
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
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(OperationsController::class)]
final class OperationsControllerExplainTest extends TestCase
{
    #[Test]
    public function explainOmitsAssetsOutsideTheCallersWorkspace(): void
    {
        $visible = $this->createMock(Asset::class);
        $visible->method('getId')->willReturn(11);
        $visible->method('getRealFullPath')->willReturn('/visible/a.jpg');
        $hidden = $this->createMock(Asset::class);
        $hidden->method('getId')->willReturn(22);
        $hidden->method('getRealFullPath')->willReturn('/secret/b.jpg');

        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(7);

        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (object $element, string $permission): bool => $element !== $hidden,
        );

        $fields = $this->createMock(AssetFieldExtractor::class);
        $fields->method('extract')->willReturn([new AssetFieldInfo('image', null, 'image', [$visible, $hidden])]);

        $rules = $this->createMock(RuleEngine::class);
        $rules->method('explain')->willReturnCallback(
            function (AbstractObject $obj, Asset $asset) use ($hidden): array {
                self::assertNotSame($hidden, $asset, 'explain must never run for a hidden asset');

                return ['evaluations' => [new RuleEvaluation('r', true, null, null, true, null, null, '/x', 1, true)]];
            },
        );

        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('dryRun')->willReturn([]);

        $controller = new class (
            $organizer,
            $this->createMock(OrganizeDispatcher::class),
            $this->createMock(AuditQueryInterface::class),
            $rules,
            $fields,
            $this->createMock(FailureReplayService::class),
            $this->createMock(AssetReorganizer::class),
            $authorization,
            $this->createMock(OperationRunStoreInterface::class),
            $this->createMock(ApplyPlanServiceInterface::class),
            new OrganizePlanFingerprint(),
            $this->createMock(ReviewedObjectOperationServiceInterface::class),
            new NullLogger(),
            new ApiDateFormatter(),
            $this->createMock(RunItemLease::class),
            new OperationResponseAssembler($this->createMock(UrlGeneratorInterface::class)),
            new OrganizeRunDispatchCoordinator($this->createMock(OrganizeDispatcher::class), $this->createMock(OperationRunStoreInterface::class), new NullLogger()),
            $this->createMock(VisibleObjectSelectorInterface::class),
            $object,
        ) extends OperationsController {
            public function __construct(
                AssetOrganizer $organizer,
                OrganizeDispatcher $dispatcher,
                AuditQueryInterface $audit,
                RuleEngine $rules,
                AssetFieldExtractor $fields,
                FailureReplayService $replay,
                AssetReorganizer $reorganizer,
                ElementAuthorization $authorization,
                OperationRunStoreInterface $runs,
                ApplyPlanServiceInterface $plans,
                OrganizePlanFingerprint $fingerprints,
                ReviewedObjectOperationServiceInterface $reviewed,
                NullLogger $logger,
                ApiDateFormatter $dates,
                RunItemLease $lease,
                OperationResponseAssembler $responses,
                OrganizeRunDispatchCoordinator $coordinator,
                VisibleObjectSelectorInterface $selector,
                private readonly AbstractObject $object,
            ) {
                parent::__construct($organizer, $dispatcher, $audit, $rules, $fields, $replay, $reorganizer, $authorization, $runs, $plans, $fingerprints, $reviewed, $logger, $dates, $lease, $responses, $coordinator, $selector);
            }

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->object;
            }
        };

        $response = $controller->explain(Request::create('/', 'POST', [], [], [], [], '{"objectId":7}'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $paths = array_column($payload['evaluations'], 'assetPath');
        self::assertContains('/visible/a.jpg', $paths);
        self::assertNotContains('/secret/b.jpg', $paths);
        self::assertNotContains(22, array_column($payload['evaluations'], 'assetId'));
    }
}
