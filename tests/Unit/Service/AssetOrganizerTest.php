<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\BulkOrganizeEvent;
use Oronts\AssetPilotBundle\Model\AssetFieldInfo;
use Oronts\AssetPilotBundle\Model\MovePlan;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleMatch;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\MovePlanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(AssetOrganizer::class)]
class AssetOrganizerTest extends TestCase
{
    private function organizer(EventDispatcher $dispatcher): AssetOrganizer
    {
        return new AssetOrganizer(
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditLogger::class),
            $dispatcher,
            $this->createMock(LoopGuard::class),
            new NullLogger(),
        );
    }

    #[Test]
    public function organizeBulkFiresBulkStartedAndCompletedEvents(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->addListener(
            AssetPilotEvents::BULK_STARTED,
            static function (BulkOrganizeEvent $e) use (&$seen): void {
                $seen[] = ['started', $e->objectIds, $e->triggerType];
            },
        );
        $dispatcher->addListener(
            AssetPilotEvents::BULK_COMPLETED,
            static function (BulkOrganizeEvent $e) use (&$seen): void {
                $seen[] = ['completed', $e->objectIds, $e->results];
            },
        );

        $this->organizer($dispatcher)->organizeBulk([], TriggerType::Api);

        self::assertSame('started', $seen[0][0]);
        self::assertSame(TriggerType::Api, $seen[0][2]);
        self::assertSame('completed', $seen[1][0]);
        self::assertSame([], $seen[1][2]);
    }

    #[Test]
    public function executeMoveSkipsWhenTheLockIsUnavailableAndReleasesNothing(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(false);
        $loopGuard->expects(self::never())->method('releaseAsset');

        $result = $this->executeMove($loopGuard, MovePlan::proceed('/target', 'f.jpg', '/target/f.jpg'));

        self::assertSame(OperationStatus::Skipped, $result->status);
    }

    #[Test]
    public function executeMoveWithASkipPlanNeverAcquiresTheAssetLock(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::never())->method('acquireAsset');

        $result = $this->executeMove($loopGuard, MovePlan::skip('/target/f.jpg', 'Asset is locked'));

        self::assertSame(OperationStatus::Skipped, $result->status);
    }

    #[Test]
    public function dryRunSelectsTheHighestPriorityRuleAcrossEveryField(): void
    {
        $object = $this->createMock(\Pimcore\Model\DataObject\Concrete::class);
        $object->method('getId')->willReturn(7);
        $object->method('getClassName')->willReturn('Product');

        $asset = $this->asset('/source/f.jpg');
        $low = Rule::fromConfig('low', ['class' => 'Product', 'fields' => ['first'], 'target_path' => '/low', 'priority' => 10]);
        $high = Rule::fromConfig('high', ['class' => 'Product', 'fields' => ['second'], 'target_path' => '/high', 'priority' => 100]);

        $engine = $this->createMock(RuleEngineInterface::class);
        $engine->method('matchField')->willReturnMap([
            [$object, $asset, 'first', null, [new RuleMatch($low, $object, $asset, '/low')]],
            [$object, $asset, 'second', null, [new RuleMatch($high, $object, $asset, '/high')]],
        ]);

        $extractor = $this->createMock(AssetFieldExtractorInterface::class);
        $extractor->method('extract')->willReturn([
            new AssetFieldInfo('first', null, 'image', [$asset]),
            new AssetFieldInfo('second', null, 'image', [$asset]),
        ]);

        $planner = $this->createMock(MovePlanner::class);
        $planner->expects(self::once())
            ->method('plan')
            ->with($asset, $object, $high, '/high', TriggerType::Manual, true)
            ->willReturn(MovePlan::proceed('/high', 'f.jpg', '/high/f.jpg'));

        $organizer = new AssetOrganizer(
            $engine,
            $extractor,
            $planner,
            $this->createMock(AuditLogger::class),
            new EventDispatcher(),
            $this->createMock(LoopGuard::class),
            new NullLogger(),
        );

        $operations = $organizer->dryRun($object);

        self::assertCount(1, $operations);
        self::assertSame('high', $operations[0]->ruleName);
        self::assertSame('/high/f.jpg', $operations[0]->targetPath);
    }

    private function executeMove(LoopGuard $loopGuard, MovePlan $plan): OperationResult
    {
        $organizer = new class (
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(MovePlanner::class),
            $this->createMock(AuditLogger::class),
            new EventDispatcher(),
            $loopGuard,
            new NullLogger(),
        ) extends AssetOrganizer {
            public function runMove(Asset $a, MovePlan $p, AbstractObject $o, Rule $r): OperationResult
            {
                return $this->executeMove($a, $p, $o, $r, TriggerType::Manual);
            }
        };

        return $organizer->runMove(
            $this->asset('/source/f.jpg'),
            $plan,
            $this->createMock(AbstractObject::class),
            Rule::fromConfig('r', ['class' => 'Product', 'target_path' => '/target']),
        );
    }

    private function asset(string $path): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);
        $asset->method('getRealFullPath')->willReturn($path);

        return $asset;
    }
}
