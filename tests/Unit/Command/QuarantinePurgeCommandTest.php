<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\QuarantinePurgeCommand;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(QuarantinePurgeCommand::class)]
final class QuarantinePurgeCommandTest extends TestCase
{
    #[Test]
    public function purgeIsPreviewByDefaultAndTheDryRunOptionIsRemoved(): void
    {
        $command = $this->command($this->createMock(QuarantineService::class));

        self::assertFalse($command->getDefinition()->hasOption('dry-run'));
        self::assertTrue($command->getDefinition()->hasOption('apply'));
        self::assertTrue($command->getDefinition()->hasOption('plan-token'));
    }

    #[Test]
    public function previewIssuesAnExactSortedSystemPlan(): void
    {
        $purge = $this->createMock(QuarantineService::class);
        $purge->expects(self::once())->method('previewPurge')->with(30)->willReturn($this->preview());
        $purge->expects(self::never())->method('purgePlanned');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('issue')->with(self::callback(static function (ApplyPlan $plan): bool {
            self::assertSame('quarantine-purge-cli', $plan->kind);
            self::assertEquals(ActorContext::system(), $plan->actor);
            self::assertSame([
                'assetIds' => [2, 7],
                'selector' => ['graceDays' => 30],
            ], $plan->request);
            self::assertSame(['asset:2', 'asset:7'], array_column($plan->targets, 'id'));
            self::assertSame(['batchSize' => 1000, 'quarantineFolder' => '/Quarantine'], $plan->config);

            return true;
        }))->willReturn('signed-plan');

        $tester = new CommandTester($this->command($purge, $plans));
        $status = $tester->execute(['--grace-days' => '30']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('signed-plan', $tester->getDisplay());
        self::assertStringContainsString('no assets were deleted', strtolower($tester->getDisplay()));
    }

    #[Test]
    public function applyRequiresAPlanTokenBeforeLoadingCandidates(): void
    {
        $purge = $this->createMock(QuarantineService::class);
        $purge->expects(self::never())->method('previewPurge');

        $status = (new CommandTester($this->command($purge)))->execute(['--apply' => true]);

        self::assertSame(Command::INVALID, $status);
    }

    #[Test]
    public function applyClaimsBeforePurgingTheExactSelection(): void
    {
        $events = [];
        $purge = $this->createMock(QuarantineService::class);
        $purge->method('previewPurge')->willReturn($this->preview());
        $purge->expects(self::once())->method('purgePlanned')->with(30, [2, 7], [
            'asset:2' => 'fingerprint-2',
            'asset:7' => 'fingerprint-7',
        ])->willReturnCallback(static function () use (&$events): array {
            $events[] = 'purge';

            return ['purged' => 2, 'skipped' => 0, 'failed' => 0];
        });
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->with('signed-plan', self::isInstanceOf(ApplyPlan::class))
            ->willReturnCallback(static function () use (&$events): ApplyPlanStatus {
                $events[] = 'claim';

                return ApplyPlanStatus::Claimed;
            });

        $status = (new CommandTester($this->command($purge, $plans)))->execute([
            '--grace-days' => '30',
            '--apply' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['claim', 'purge'], $events);
    }

    #[Test]
    public function stalePlanIsRejectedWithoutPurging(): void
    {
        $purge = $this->createMock(QuarantineService::class);
        $purge->method('previewPurge')->willReturn($this->preview());
        $purge->expects(self::never())->method('purgePlanned');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->method('claim')->willReturn(ApplyPlanStatus::Stale);

        $status = (new CommandTester($this->command($purge, $plans)))->execute([
            '--grace-days' => '30',
            '--apply' => true,
            '--plan-token' => 'stale',
        ]);

        self::assertSame(Command::INVALID, $status);
    }

    #[Test]
    public function graceDaysMustBeANonNegativeBoundedInteger(): void
    {
        $purge = $this->createMock(QuarantineService::class);
        $purge->expects(self::never())->method('previewPurge');
        $tester = new CommandTester($this->command($purge));

        self::assertSame(Command::INVALID, $tester->execute(['--grace-days' => '-1']));
        self::assertSame(Command::INVALID, $tester->execute(['--grace-days' => '1.5']));
    }

    private function command(QuarantineService $purge, ?ApplyPlanServiceInterface $plans = null): QuarantinePurgeCommand
    {
        $plans ??= $this->createMock(ApplyPlanServiceInterface::class);

        return new QuarantinePurgeCommand($purge, $plans);
    }

    /**
     * @return array{graceDays: int, assetIds: list<int>, config: array<string, mixed>, targets: list<ApplyPlanTarget>, result: array{purged: int, skipped: int, failed: int}}
     */
    private function preview(): array
    {
        return [
            'graceDays' => 30,
            'assetIds' => [7, 2],
            'config' => ['batchSize' => 1000, 'quarantineFolder' => '/Quarantine'],
            'targets' => [
                new ApplyPlanTarget('asset:7', 'fingerprint-7'),
                new ApplyPlanTarget('asset:2', 'fingerprint-2'),
            ],
            'result' => ['purged' => 2, 'skipped' => 0, 'failed' => 0],
        ];
    }
}
