<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\MergeDuplicatesCommand;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\MergeOutcome;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MergeDuplicatesCommand::class)]
class MergeDuplicatesCommandTest extends TestCase
{
    private function tester(DuplicateDetectionService $detection, DuplicateMergeService $merge, ?ApplyPlanServiceInterface $plans = null): CommandTester
    {
        $plans ??= $this->planService();

        return new CommandTester(new MergeDuplicatesCommand($detection, $merge, $plans));
    }

    private function detectionReturning(?DuplicateGroup $group): DuplicateDetectionService
    {
        $detection = $this->createMock(DuplicateDetectionService::class);
        $detection->method('groupForChecksum')->willReturn($group);

        return $detection;
    }

    private function mergeService(): DuplicateMergeService
    {
        $merge = $this->createMock(DuplicateMergeService::class);
        $merge->method('availableStrategies')->willReturn(['quarantine', 'delete', 'isolate']);
        $merge->method('defaultStrategyName')->willReturn('quarantine');
        $merge->method('planTargets')->willReturnCallback(static function (DuplicateGroup $group, int $canonicalId): array {
            self::assertContains($canonicalId, $group->assetIds);
            $assetIds = $group->assetIds;
            sort($assetIds, SORT_NUMERIC);

            return array_map(
                static fn (int $id): ApplyPlanTarget => new ApplyPlanTarget('asset:' . $id, 'fingerprint-' . $id),
                $assetIds,
            );
        });

        return $merge;
    }

    private function planService(): ApplyPlanServiceInterface
    {
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->method('issue')->willReturn('signed-plan');
        $plans->method('claim')->willReturn(ApplyPlanStatus::Claimed);

        return $plans;
    }

    #[Test]
    public function previewsByDefaultWithoutMerging(): void
    {
        $merge = $this->mergeService();
        $merge->expects(self::once())->method('preview')
            ->with(self::isInstanceOf(DuplicateGroup::class), 3, 'quarantine')
            ->willReturn(new MergeOutcome('abc', 3, [new CopyDisposition(9, DispositionOutcome::Skipped, 'dry run: would repoint and dispose')]));

        $tester = $this->tester($this->detectionReturning(new DuplicateGroup('abc', 100, 2, [3, 9])), $merge);
        $exit = $tester->execute(['--checksum' => 'abc']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('signed-plan', $tester->getDisplay());
    }

    #[Test]
    public function appliesTheMergeWithApply(): void
    {
        $merge = $this->mergeService();
        $merge->expects(self::once())->method('merge')
            ->with(self::isInstanceOf(DuplicateGroup::class), [
                'asset:3' => 'fingerprint-3',
                'asset:9' => 'fingerprint-9',
            ], 9, 'delete')
            ->willReturn(new MergeOutcome('abc', 9, [new CopyDisposition(3, DispositionOutcome::Deleted)]));

        $tester = $this->tester($this->detectionReturning(new DuplicateGroup('abc', 100, 2, [3, 9])), $merge);
        $exit = $tester->execute([
            '--checksum' => 'abc',
            '--apply' => true,
            '--plan-token' => 'signed-plan',
            '--canonical' => '9',
            '--strategy' => 'delete',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
    }

    #[Test]
    public function previewIssuesAnExactSortedSystemPlan(): void
    {
        $merge = $this->mergeService();
        $merge->method('preview')->willReturn(new MergeOutcome('abc', 3, []));
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('issue')->with(self::callback(static function (ApplyPlan $plan): bool {
            self::assertSame('duplicate-merge-cli', $plan->kind);
            self::assertEquals(ActorContext::system(), $plan->actor);
            self::assertSame([
                'assetIds' => [3, 9],
                'canonicalId' => 3,
                'checksum' => 'abc',
                'strategy' => 'quarantine',
            ], $plan->request);
            self::assertSame(['asset:3', 'asset:9'], array_column($plan->targets, 'id'));

            return true;
        }))->willReturn('signed-plan');

        $status = $this->tester(
            $this->detectionReturning(new DuplicateGroup('abc', 100, 2, [9, 3])),
            $merge,
            $plans,
        )->execute(['--checksum' => 'abc']);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function applyRequiresAPlanTokenBeforeLoadingTheGroup(): void
    {
        $detection = $this->createMock(DuplicateDetectionService::class);
        $detection->expects(self::never())->method('groupForChecksum');

        $status = $this->tester($detection, $this->mergeService())->execute([
            '--checksum' => 'abc',
            '--apply' => true,
        ]);

        self::assertSame(Command::INVALID, $status);
    }

    #[Test]
    public function stalePlanIsRejectedBeforeMerge(): void
    {
        $merge = $this->mergeService();
        $merge->expects(self::never())->method('merge');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->willReturn(ApplyPlanStatus::Stale);

        $status = $this->tester(
            $this->detectionReturning(new DuplicateGroup('abc', 100, 2, [3, 9])),
            $merge,
            $plans,
        )->execute(['--checksum' => 'abc', '--apply' => true, '--plan-token' => 'stale']);

        self::assertSame(Command::INVALID, $status);
    }

    #[Test]
    public function resumesAPersistedRunWithApplyAndPrintsItsStatus(): void
    {
        $runId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $merge = $this->mergeService();
        $merge->expects(self::once())->method('resume')->with($runId)->willReturn(new MergeOutcome(
            'abc',
            3,
            [new CopyDisposition(9, DispositionOutcome::Quarantined)],
            $runId,
            OperationRunStatus::Completed,
        ));

        $tester = $this->tester($this->detectionReturning(null), $merge);
        $exit = $tester->execute(['--run-id' => $runId, '--apply' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString($runId, $tester->getDisplay());
        self::assertStringContainsString('completed', $tester->getDisplay());
    }

    #[Test]
    public function refusesToResumeWithoutExplicitApply(): void
    {
        $merge = $this->mergeService();
        $merge->expects(self::never())->method('resume');

        $tester = $this->tester($this->detectionReturning(null), $merge);
        $exit = $tester->execute(['--run-id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('requires --apply', $tester->getDisplay());
    }

    #[Test]
    public function failsWhenNoGroupExistsForTheChecksum(): void
    {
        $tester = $this->tester($this->detectionReturning(null), $this->mergeService());
        $exit = $tester->execute(['--checksum' => 'missing']);

        self::assertSame(Command::FAILURE, $exit);
    }

    #[Test]
    public function rejectsAnUnknownStrategyBeforeTouchingData(): void
    {
        $merge = $this->mergeService();
        $merge->expects(self::never())->method('merge');

        $tester = $this->tester($this->detectionReturning(new DuplicateGroup('abc', 100, 2, [3, 9])), $merge);
        $exit = $tester->execute(['--checksum' => 'abc', '--strategy' => 'nope']);

        self::assertSame(Command::INVALID, $exit);
    }

    #[Test]
    public function canonicalMustBeAPositiveGroupMember(): void
    {
        $merge = $this->mergeService();
        $merge->expects(self::never())->method('merge');
        $tester = $this->tester(
            $this->detectionReturning(new DuplicateGroup('abc', 100, 2, [3, 9])),
            $merge,
        );

        self::assertSame(Command::INVALID, $tester->execute(['--checksum' => 'abc', '--canonical' => 'invalid']));
        self::assertSame(Command::INVALID, $tester->execute(['--checksum' => 'abc', '--canonical' => '7']));
    }

    #[Test]
    public function requiresAChecksum(): void
    {
        $tester = $this->tester($this->detectionReturning(null), $this->mergeService());
        $exit = $tester->execute([]);

        self::assertSame(Command::INVALID, $exit);
    }

    #[Test]
    public function applyFailsWhenACopyDispositionRemainsUnresolved(): void
    {
        $merge = $this->mergeService();
        $merge->expects(self::once())->method('merge')
            ->with(self::isInstanceOf(DuplicateGroup::class), [
                'asset:3' => 'fingerprint-3',
                'asset:9' => 'fingerprint-9',
            ], 3, 'quarantine')
            ->willReturn(new MergeOutcome('abc', 3, [new CopyDisposition(9, DispositionOutcome::LeftError, 'copy could not be deleted')]));

        $tester = $this->tester($this->detectionReturning(new DuplicateGroup('abc', 100, 2, [3, 9])), $merge);
        $exit = $tester->execute(['--checksum' => 'abc', '--apply' => true, '--plan-token' => 'signed-plan']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('unresolved', strtolower($tester->getDisplay()));
    }

}
