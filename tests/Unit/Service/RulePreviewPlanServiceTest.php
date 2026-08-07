<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\RulePreviewPlanStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Service\ApplyPlanService;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use Oronts\AssetPilotBundle\Service\RulePreviewPlanService;
use Oronts\AssetPilotBundle\Tests\Support\InMemoryApplyPlanClaimStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;

#[CoversClass(RulePreviewPlanService::class)]
final class RulePreviewPlanServiceTest extends TestCase
{
    #[Test]
    public function validTokenAcceptsTheSameDeterministicPlanInAnyOperationOrder(): void
    {
        $service = $this->service();
        $rule = $this->rule();
        $object = $this->object();
        $actor = ActorContext::user(7);
        $first = $this->operation(5, '/products/a.jpg');
        $second = $this->operation(6, '/products/b.jpg');

        $token = $service->issue($rule, $object, $actor, [$second, $first]);

        self::assertSame(
            RulePreviewPlanStatus::Valid,
            $service->verify($token, $rule, $object, $actor, [$first, $second]),
        );
    }

    #[Test]
    public function tokenIsStaleWhenActorObjectRuleOrOperationsChange(): void
    {
        $service = $this->service();
        $rule = $this->rule();
        $object = $this->object();
        $actor = ActorContext::user(7);
        $operations = [$this->operation(5, '/products/a.jpg')];
        $token = $service->issue($rule, $object, $actor, $operations);

        self::assertSame(
            RulePreviewPlanStatus::Stale,
            $service->verify($token, $rule, $object, ActorContext::user(8), $operations),
        );
        self::assertSame(
            RulePreviewPlanStatus::Stale,
            $service->verify($token, $rule, $this->object(901), $actor, $operations),
        );
        self::assertSame(
            RulePreviewPlanStatus::Stale,
            $service->verify($token, $rule, $this->object(900, '/changed/42'), $actor, $operations),
        );
        self::assertSame(
            RulePreviewPlanStatus::Stale,
            $service->verify($token, $rule, $this->object(900, '/products/42', 4), $actor, $operations),
        );
        self::assertSame(
            RulePreviewPlanStatus::Stale,
            $service->verify($token, $this->rule('/changed/{object.id}'), $object, $actor, $operations),
        );
        self::assertSame(
            RulePreviewPlanStatus::Stale,
            $service->verify($token, $rule, $object, $actor, [$this->operation(5, '/changed/a.jpg')]),
        );
    }

    #[Test]
    public function expiredTokenIsStale(): void
    {
        $now = 1_000;
        $service = $this->service($now, 60);
        $rule = $this->rule();
        $object = $this->object();
        $actor = ActorContext::system();
        $operations = [$this->operation(5, '/products/a.jpg')];
        $token = $service->issue($rule, $object, $actor, $operations);

        $now = 1_060;

        self::assertSame(
            RulePreviewPlanStatus::Stale,
            $service->verify($token, $rule, $object, $actor, $operations),
        );
    }

    #[Test]
    public function claimIsSingleUse(): void
    {
        $service = $this->service();
        $rule = $this->rule();
        $object = $this->object();
        $actor = ActorContext::user(7);
        $operations = [$this->operation(5, '/products/a.jpg')];
        $token = $service->issue($rule, $object, $actor, $operations);

        self::assertSame(RulePreviewPlanStatus::Valid, $service->claim($token, $rule, $object, $actor, $operations));
        self::assertSame(RulePreviewPlanStatus::Stale, $service->claim($token, $rule, $object, $actor, $operations));
    }

    #[Test]
    public function malformedOrTamperedTokenIsRejected(): void
    {
        $service = $this->service();
        $rule = $this->rule();
        $object = $this->object();
        $actor = ActorContext::anonymous();
        $operations = [$this->operation(5, '/products/a.jpg')];
        $token = $service->issue($rule, $object, $actor, $operations);

        self::assertSame(
            RulePreviewPlanStatus::Malformed,
            $service->verify('not-a-token', $rule, $object, $actor, $operations),
        );
        self::assertSame(
            RulePreviewPlanStatus::Malformed,
            $service->verify($token . 'x', $rule, $object, $actor, $operations),
        );
    }

    private function service(int &$now = 1_000, int $ttlSeconds = 300): RulePreviewPlanService
    {
        $claims = new InMemoryApplyPlanClaimStore();

        return new RulePreviewPlanService(new ApplyPlanService(
            'test-rule-preview-secret',
            $claims,
            ttlSeconds: $ttlSeconds,
            clock: static function () use (&$now): int {
                return $now;
            },
        ), new OrganizePlanFingerprint());
    }

    private function object(
        int $modifiedAt = 900,
        string $fullPath = '/products/42',
        int $versionCount = 3,
    ): AbstractObject {
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(42);
        $object->method('getModificationDate')->willReturn($modifiedAt);
        $object->method('getRealFullPath')->willReturn($fullPath);
        $object->method('getType')->willReturn('object');
        $object->method('getVersionCount')->willReturn($versionCount);

        return $object;
    }

    private function rule(string $targetPath = '/products/{object.id}'): Rule
    {
        return new Rule(
            name: 'product-assets',
            class: 'Product',
            fields: ['images'],
            condition: null,
            targetPath: $targetPath,
            strategy: MoveStrategy::Always,
            callback: null,
            priority: 10,
            enabled: true,
            filters: ['extensions' => ['jpg', 'png']],
            options: ['quality' => 90],
        );
    }

    private function operation(int $assetId, string $targetPath): MoveOperation
    {
        return new MoveOperation(
            assetId: $assetId,
            sourcePath: sprintf('/incoming/%d.jpg', $assetId),
            targetPath: $targetPath,
            objectId: 42,
            objectClass: 'Product',
            ruleName: 'product-assets',
            status: OperationStatus::Pending,
            triggerType: TriggerType::Api,
        );
    }
}
