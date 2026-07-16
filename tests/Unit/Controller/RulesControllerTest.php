<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Controller\Api\RulesController;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\RulePreviewPlanStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanService;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LocationDriftService;
use Oronts\AssetPilotBundle\Service\RuleOverlapAnalyzer;
use Oronts\AssetPilotBundle\Service\RulePortability;
use Oronts\AssetPilotBundle\Service\RulePreviewPlanService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(RulesController::class)]
final class RulesControllerTest extends TestCase
{
    private const string SECRET = 'test-rule-preview-secret';

    #[Test]
    public function previewReturnsNotFoundForAnUnknownRuleBeforeLoadingTheObject(): void
    {
        $rules = $this->createMock(RuleEngineInterface::class);
        $rules->method('getRules')->willReturn([]);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('dryRun');

        $response = $this->controller($rules, $organizer)->preview('missing', Request::create('/?objectId=42'));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Rule "missing" not found.'],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function previewReturnsOperationsAndActorBoundPlanToken(): void
    {
        $rule = $this->rule();
        $operation = $this->operation();
        $object = $this->object();
        $actor = ActorContext::user(7);
        $rules = $this->rules($rule);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('dryRun')
            ->with($object, TriggerType::Api, $rule->name)
            ->willReturn([$operation]);
        $authorization = $this->authorization($actor);
        $plans = $this->plans();

        $response = $this->controller($rules, $organizer, $authorization, $object, $plans)
            ->preview($rule->name, Request::create('/?objectId=42'));
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([[
            'assetId' => 5,
            'sourcePath' => '/incoming/a.jpg',
            'targetPath' => '/products/a.jpg',
            'ruleName' => 'product-assets',
        ]], $body['operations']);
        self::assertSame(
            RulePreviewPlanStatus::Valid,
            $plans->verify($body['planToken'], $rule, $object, $actor, [$operation]),
        );
    }

    #[Test]
    public function applyRejectsMissingPlanTokenBeforeMutation(): void
    {
        $rule = $this->rule();
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::never())->method('dryRun');
        $organizer->expects(self::never())->method('organize');

        $response = $this->controller($this->rules($rule), $organizer)->apply(
            $rule->name,
            $this->jsonRequest(['objectId' => 42]),
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('planToken', (string) $response->getContent());
    }

    #[Test]
    public function applyRejectsMalformedPlanTokenBeforeMutation(): void
    {
        $rule = $this->rule();
        $object = $this->object();
        $operation = $this->operation();
        $actor = ActorContext::user(7);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('dryRun')->willReturn([$operation]);
        $organizer->expects(self::never())->method('organize');

        $response = $this->controller(
            $this->rules($rule),
            $organizer,
            $this->authorization($actor),
            $object,
        )->apply($rule->name, $this->jsonRequest(['objectId' => 42, 'planToken' => 'not-a-token']));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('planToken', (string) $response->getContent());
    }

    #[Test]
    public function applyRejectsStaleOperationSnapshotBeforeMutation(): void
    {
        $rule = $this->rule();
        $object = $this->object();
        $actor = ActorContext::user(7);
        $previewOperation = $this->operation();
        $currentOperation = $this->operation('/products/changed.jpg');
        $plans = $this->plans();
        $token = $plans->issue($rule, $object, $actor, [$previewOperation]);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())->method('dryRun')->willReturn([$currentOperation]);
        $organizer->expects(self::never())->method('organize');

        $response = $this->controller(
            $this->rules($rule),
            $organizer,
            $this->authorization($actor),
            $object,
            $plans,
        )->apply($rule->name, $this->jsonRequest(['objectId' => 42, 'planToken' => $token]));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertStringContainsString('stale', strtolower((string) $response->getContent()));
    }

    #[Test]
    public function applyRecomputesValidPlanImmediatelyBeforeOrganizing(): void
    {
        $rule = $this->rule();
        $object = $this->object();
        $actor = ActorContext::user(7);
        $operation = $this->operation();
        $plans = $this->plans();
        $token = $plans->issue($rule, $object, $actor, [$operation]);
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::exactly(2))->method('dryRun')
            ->with($object, TriggerType::Api, $rule->name)
            ->willReturn([$operation]);
        $organizer->expects(self::once())->method('organize')
            ->with($object, TriggerType::Api, $rule->name)
            ->willReturn([]);

        $response = $this->controller(
            $this->rules($rule),
            $organizer,
            $this->authorization($actor),
            $object,
            $plans,
        )->apply($rule->name, $this->jsonRequest(['objectId' => 42, 'planToken' => $token]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $reused = $this->controller(
            $this->rules($rule),
            $organizer,
            $this->authorization($actor),
            $object,
            $plans,
        )->apply($rule->name, $this->jsonRequest(['objectId' => 42, 'planToken' => $token]));
        self::assertSame(Response::HTTP_CONFLICT, $reused->getStatusCode());
    }

    private function controller(
        RuleEngineInterface $rules,
        AssetOrganizer $organizer,
        ?ElementAuthorization $authorization = null,
        ?AbstractObject $object = null,
        ?RulePreviewPlanService $plans = null,
    ): RulesController {
        $controller = new class (
            $rules,
            $organizer,
            $this->createMock(AuditLoggerInterface::class),
            $this->createMock(RulePortability::class),
            $this->createMock(RuleOverlapAnalyzer::class),
            $this->createMock(LocationDriftService::class),
            $plans ?? $this->plans(),
            $authorization ?? $this->createMock(ElementAuthorization::class),
            new NullLogger(),
        ) extends RulesController {
            public static ?AbstractObject $object = null;

            protected function loadObject(int $objectId): ?AbstractObject
            {
                return self::$object;
            }
        };
        $controller::$object = $object;

        return $controller;
    }

    private function plans(): RulePreviewPlanService
    {
        return new RulePreviewPlanService(new ApplyPlanService(
            self::SECRET,
            new ArrayAdapter(),
            new LockFactory(new InMemoryStore()),
            clock: static fn (): int => 1_000,
        ));
    }

    private function rules(Rule $rule): RuleEngineInterface
    {
        $rules = $this->createMock(RuleEngineInterface::class);
        $rules->method('getRules')->willReturn([$rule]);

        return $rules;
    }

    private function authorization(ActorContext $actor): ElementAuthorization
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);
        $authorization->method('isAllowed')->willReturn(true);

        return $authorization;
    }

    private function object(): AbstractObject
    {
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(42);
        $object->method('getModificationDate')->willReturn(900);

        return $object;
    }

    private function rule(): Rule
    {
        return new Rule(
            name: 'product-assets',
            class: 'Product',
            fields: ['images'],
            condition: null,
            targetPath: '/products/{object.id}',
            strategy: MoveStrategy::Always,
            callback: null,
            priority: 10,
            enabled: true,
            filters: [],
        );
    }

    private function operation(string $targetPath = '/products/a.jpg'): MoveOperation
    {
        return new MoveOperation(
            assetId: 5,
            sourcePath: '/incoming/a.jpg',
            targetPath: $targetPath,
            objectId: 42,
            objectClass: 'Product',
            ruleName: 'product-assets',
            status: OperationStatus::Pending,
            triggerType: TriggerType::Api,
        );
    }

    private function jsonRequest(array $body): Request
    {
        return Request::create('/', 'POST', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
