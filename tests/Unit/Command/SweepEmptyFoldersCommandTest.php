<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\SweepEmptyFoldersCommand;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\EmptyFolderSweepService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SweepEmptyFoldersCommand::class)]
final class SweepEmptyFoldersCommandTest extends TestCase
{
    #[Test]
    public function obsoleteDeleteFlagIsRemoved(): void
    {
        $command = $this->command($this->createMock(EmptyFolderSweepService::class));

        self::assertFalse($command->getDefinition()->hasOption('delete'));
        self::assertTrue($command->getDefinition()->hasOption('apply'));
        self::assertTrue($command->getDefinition()->hasOption('plan-token'));
    }

    #[Test]
    public function previewDoesNotDelete(): void
    {
        $sweep = $this->createMock(EmptyFolderSweepService::class);
        $sweep->method('findEmpty')->willReturn(['items' => [['id' => 5, 'path' => '/empty']], 'page' => 1, 'limit' => 10]);
        $sweep->method('createDeletePlan')->willReturn($this->deletePlan());
        $sweep->expects(self::never())->method('deleteEmpty');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('issue')->with(self::callback(static function (ApplyPlan $plan): bool {
            self::assertEquals(ActorContext::system(), $plan->actor);
            self::assertSame([
                'folderIds' => [5],
                'selector' => ['folder' => null, 'limit' => 10],
            ], $plan->request);
            self::assertSame(['folder:5'], array_column($plan->targets, 'id'));

            return true;
        }))->willReturn('signed-plan');

        $tester = new CommandTester($this->command($sweep, $plans));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '10']));
        self::assertStringContainsString('signed-plan', $tester->getDisplay());
    }

    #[Test]
    public function applyUsesTheFreshFolderFingerprints(): void
    {
        $plan = $this->deletePlan();
        $sweep = $this->createMock(EmptyFolderSweepService::class);
        $sweep->method('findEmpty')->willReturn(['items' => [['id' => 5, 'path' => '/empty']], 'page' => 1, 'limit' => 10]);
        $sweep->expects(self::once())->method('createDeletePlan')->with([5])->willReturn($plan);
        $sweep->expects(self::once())->method('deleteEmpty')->with([5], ['folder:5' => 'fingerprint-5'])->willReturn([
            'deleted' => 1,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ]);

        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->with('signed-plan', self::isInstanceOf(ApplyPlan::class))->willReturn(ApplyPlanStatus::Claimed);
        $tester = new CommandTester($this->command($sweep, $plans));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--limit' => '10',
            '--apply' => true,
            '--plan-token' => 'signed-plan',
        ]));
        self::assertStringContainsString('Swept 1 empty folder', $tester->getDisplay());
    }

    #[Test]
    public function applyRequiresAPlanTokenBeforeSelectingFolders(): void
    {
        $sweep = $this->createMock(EmptyFolderSweepService::class);
        $sweep->expects(self::never())->method('findEmpty');

        $status = (new CommandTester($this->command($sweep)))->execute(['--apply' => true]);

        self::assertSame(Command::INVALID, $status);
    }

    #[Test]
    public function stalePlanDoesNotDelete(): void
    {
        $sweep = $this->createMock(EmptyFolderSweepService::class);
        $sweep->method('findEmpty')->willReturn(['items' => [['id' => 5, 'path' => '/empty']], 'page' => 1, 'limit' => 10]);
        $sweep->method('createDeletePlan')->willReturn($this->deletePlan());
        $sweep->expects(self::never())->method('deleteEmpty');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->method('claim')->willReturn(ApplyPlanStatus::Stale);

        $status = (new CommandTester($this->command($sweep, $plans)))->execute([
            '--limit' => '10',
            '--apply' => true,
            '--plan-token' => 'stale',
        ]);

        self::assertSame(Command::INVALID, $status);
    }

    #[Test]
    public function rejectsAnOutOfRangeLimit(): void
    {
        $sweep = $this->createMock(EmptyFolderSweepService::class);
        $sweep->expects(self::never())->method('findEmpty');

        self::assertSame(Command::INVALID, (new CommandTester($this->command($sweep)))->execute(['--limit' => '1001']));
    }

    private function command(EmptyFolderSweepService $sweep, ?ApplyPlanServiceInterface $plans = null): SweepEmptyFoldersCommand
    {
        $plans ??= $this->createMock(ApplyPlanServiceInterface::class);

        return new SweepEmptyFoldersCommand($sweep, $plans);
    }

    private function deletePlan(): ApplyPlan
    {
        return new ApplyPlan(
            'empty-folder-delete',
            ActorContext::system(),
            ['folderIds' => [5]],
            ['version' => 1],
            [new ApplyPlanTarget('folder:5', 'fingerprint-5')],
        );
    }
}
