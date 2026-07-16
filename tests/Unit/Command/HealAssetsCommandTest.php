<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\HealAssetsCommand;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\HealOutcome;
use Oronts\AssetPilotBundle\Enum\UndoHealOutcome;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Model\HealResult;
use Oronts\AssetPilotBundle\Model\UndoHealResult;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetIntegrityService;
use Oronts\AssetPilotBundle\Service\IntegrityHealFingerprintService;
use Oronts\AssetPilotBundle\Service\IntegrityHealLog;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(HealAssetsCommand::class)]
class HealAssetsCommandTest extends TestCase
{
    #[Test]
    public function deprecatedDryRunAliasIsRemoved(): void
    {
        $command = $this->command(
            $this->createMock(AssetIntegrityService::class),
            $this->createMock(VersionRollbackHealer::class),
        );

        self::assertFalse($command->getDefinition()->hasOption('dry-run'));
    }

    #[Test]
    public function namedAssetsArePreviewedByDefault(): void
    {
        $integrity = $this->createMock(AssetIntegrityService::class);
        $integrity->method('checkAssets')->with([7])->willReturn($this->brokenScan());
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->expects(self::once())->method('previewById')->with(7)->willReturn(new HealResult(HealOutcome::Healed, 'stub', 2, null, true));

        $tester = new CommandTester($this->command($integrity, $healer));
        $status = $tester->execute(['--by-ids' => '7']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Plan token:', $tester->getDisplay());
    }

    #[Test]
    public function applyExplicitlyEnablesHealing(): void
    {
        $integrity = $this->createMock(AssetIntegrityService::class);
        $integrity->method('checkAssets')->willReturn($this->brokenScan());
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->method('previewById')->with(7)->willReturn(new HealResult(HealOutcome::Healed, 'stub', 2, null, true));
        $healer->expects(self::once())->method('healPlannedBatch')->with(
            [7],
            ['asset:7' => 'plan-7'],
        )->willReturn([7 => new HealResult(HealOutcome::Healed, 'stub', 2)]);

        $status = (new CommandTester($this->command($integrity, $healer)))->execute([
            '--by-ids' => '7',
            '--apply' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function scanRequiresAnExplicitSelectorOrAll(): void
    {
        $integrity = $this->createMock(AssetIntegrityService::class);
        $integrity->expects(self::never())->method('findBroken');

        $status = (new CommandTester($this->command($integrity, $this->createMock(VersionRollbackHealer::class))))->execute([]);

        self::assertSame(Command::INVALID, $status);
    }

    #[Test]
    public function malformedOrUnboundedLimitsAreRejectedBeforeScanning(): void
    {
        foreach (['invalid', '0', '1001', '1.5'] as $limit) {
            $integrity = $this->createMock(AssetIntegrityService::class);
            $integrity->expects(self::never())->method('findBroken');
            $tester = new CommandTester($this->command($integrity, $this->createMock(VersionRollbackHealer::class)));

            self::assertSame(Command::INVALID, $tester->execute(['--all' => true, '--limit' => $limit]));
            self::assertStringContainsString('between 1 and 1000', $tester->getDisplay());
        }
    }

    #[Test]
    public function undoIsAlsoPreviewedUnlessApplyIsPresent(): void
    {
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->expects(self::never())->method('undo');
        $healer->expects(self::once())->method('undoDetailed')->with(7, true)->willReturn(new UndoHealResult(UndoHealOutcome::WouldReverse, dryRun: true));

        $tester = new CommandTester($this->command($this->createMock(AssetIntegrityService::class), $healer));
        $status = $tester->execute([
            '--by-ids' => '7',
            '--undo' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('would reverse', $tester->getDisplay());
    }

    #[Test]
    public function appliedUndoTouchesOnlyNamedAssets(): void
    {
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->expects(self::exactly(2))->method('undoDetailed')->willReturnMap([
            [7, true, new UndoHealResult(UndoHealOutcome::WouldReverse, dryRun: true)],
            [7, false, new UndoHealResult(UndoHealOutcome::Reversed)],
        ]);

        $status = (new CommandTester($this->command($this->createMock(AssetIntegrityService::class), $healer)))->execute([
            '--by-ids' => '7',
            '--undo' => true,
            '--apply' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function skippedApplyReturnsFailure(): void
    {
        $integrity = $this->createMock(AssetIntegrityService::class);
        $integrity->method('checkAssets')->willReturn($this->brokenScan());
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->method('previewById')->willReturn(new HealResult(HealOutcome::Skipped, 'none', reason: 'Asset is locked.', dryRun: true));
        $healer->method('healPlannedBatch')->willReturn([7 => new HealResult(HealOutcome::Skipped, 'none', reason: 'Asset is locked.')]);

        $status = (new CommandTester($this->command($integrity, $healer)))->execute([
            '--by-ids' => '7',
            '--apply' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::FAILURE, $status);
    }

    #[Test]
    public function failedUndoReturnsFailure(): void
    {
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->method('undoDetailed')->willReturn(new UndoHealResult(UndoHealOutcome::Skipped, 'Asset is locked.'));

        $tester = new CommandTester($this->command($this->createMock(AssetIntegrityService::class), $healer));
        $status = $tester->execute([
            '--by-ids' => '7',
            '--undo' => true,
            '--apply' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Asset is locked.', $tester->getDisplay());
    }

    #[Test]
    public function failedUndoPreviewReturnsFailureAndShowsTheReason(): void
    {
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->method('undoDetailed')->with(7, true)->willReturn(new UndoHealResult(UndoHealOutcome::Skipped, 'No reversible heal is recorded for this asset.', true));

        $tester = new CommandTester($this->command($this->createMock(AssetIntegrityService::class), $healer));
        $status = $tester->execute(['--by-ids' => '7', '--undo' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No reversible heal is recorded for this asset.', $tester->getDisplay());
    }

    #[Test]
    public function failedHealPreviewDoesNotClaimThatAnIntegrityLogExists(): void
    {
        $integrity = $this->createMock(AssetIntegrityService::class);
        $integrity->method('checkAssets')->willReturn($this->brokenScan());
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->method('previewById')->willReturn(new HealResult(HealOutcome::Skipped, 'none', reason: 'Asset is locked.', dryRun: true));

        $tester = new CommandTester($this->command($integrity, $healer));
        $status = $tester->execute(['--by-ids' => '7']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Asset is locked.', $tester->getDisplay());
        self::assertStringNotContainsString('integrity log', $tester->getDisplay());
    }

    #[Test]
    public function applyRequiresAPlanTokenBeforeScanning(): void
    {
        $integrity = $this->createMock(AssetIntegrityService::class);
        $integrity->expects(self::never())->method('checkAssets');

        $tester = new CommandTester($this->command($integrity, $this->createMock(VersionRollbackHealer::class)));

        self::assertSame(Command::INVALID, $tester->execute(['--by-ids' => '7', '--apply' => true]));
        self::assertStringContainsString('--plan-token', $tester->getDisplay());
    }

    #[Test]
    public function planTokenIsRejectedDuringPreview(): void
    {
        $integrity = $this->createMock(AssetIntegrityService::class);
        $integrity->expects(self::never())->method('checkAssets');

        $tester = new CommandTester($this->command($integrity, $this->createMock(VersionRollbackHealer::class)));

        self::assertSame(Command::INVALID, $tester->execute(['--by-ids' => '7', '--plan-token' => 'token']));
        self::assertStringContainsString('only valid together with --apply', $tester->getDisplay());
    }

    #[Test]
    public function stalePlanIsRejectedBeforeTheFirstHealMutation(): void
    {
        $integrity = $this->createMock(AssetIntegrityService::class);
        $integrity->method('checkAssets')->willReturn($this->brokenScan());
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->method('previewById')->willReturn(new HealResult(HealOutcome::Healed, 'stub', 2, dryRun: true));
        $healer->expects(self::never())->method('healPlannedBatch');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->willReturn(ApplyPlanStatus::Stale);

        $tester = new CommandTester($this->command($integrity, $healer, $plans));
        $status = $tester->execute([
            '--by-ids' => '7',
            '--apply' => true,
            '--plan-token' => 'stale',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Preview again', $tester->getDisplay());
    }

    #[Test]
    public function undoPreviewRejectsAChangedHealRecordWithoutIssuingAToken(): void
    {
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->expects(self::once())->method('undoDetailed')->with(7, true)
            ->willReturn(new UndoHealResult(UndoHealOutcome::WouldReverse, dryRun: true));
        $healLog = $this->createMock(IntegrityHealLog::class);
        $healLog->expects(self::exactly(2))->method('findUndoable')->with(7)->willReturnOnConsecutiveCalls(
            ['id' => 70, 'from_version' => 1, 'to_version' => 2],
            ['id' => 71, 'from_version' => 3, 'to_version' => 4],
        );
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::never())->method('issue');
        $tester = new CommandTester($this->command(
            $this->createMock(AssetIntegrityService::class),
            $healer,
            $plans,
            healLog: $healLog,
        ));

        $status = $tester->execute(['--by-ids' => '7', '--undo' => true]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('heal record changed', $tester->getDisplay());
    }

    /** @return array{scanned: int, broken: int, unverifiable: int, items: list<array{id: int, path: string}>} */
    private function brokenScan(): array
    {
        return ['scanned' => 1, 'broken' => 1, 'unverifiable' => 0, 'items' => [['id' => 7, 'path' => '/p/x.jpg']]];
    }

    private function command(
        AssetIntegrityService $integrity,
        VersionRollbackHealer $healer,
        ?ApplyPlanServiceInterface $plans = null,
        ?IntegrityHealFingerprintService $fingerprints = null,
        ?IntegrityHealLog $healLog = null,
    ): HealAssetsCommand {
        if ($plans === null) {
            $plans = $this->createMock(ApplyPlanServiceInterface::class);
            $plans->method('issue')->willReturn('signed-plan');
            $plans->method('claim')->willReturn(ApplyPlanStatus::Claimed);
        }
        if ($fingerprints === null) {
            $fingerprints = $this->createMock(IntegrityHealFingerprintService::class);
            $fingerprints->method('fingerprintMap')->willReturnCallback(static function (array $assetIds): array {
                $state = [];
                foreach ($assetIds as $assetId) {
                    $state['asset:' . $assetId] = 'state-' . $assetId;
                }

                return $state;
            });
            $fingerprints->method('planConfig')->willReturn(['version' => 1]);
            $fingerprints->method('targets')->willReturnCallback(static fn (array $assetIds): array => array_map(
                static fn (int $assetId): ApplyPlanTarget => new ApplyPlanTarget('asset:' . $assetId, 'plan-' . $assetId),
                $assetIds,
            ));
        }

        if ($healLog === null) {
            $healLog = $this->createMock(IntegrityHealLog::class);
            $healLog->method('findUndoable')->willReturn([
                'id' => 70,
                'from_version' => 1,
                'to_version' => 2,
            ]);
        }

        return new HealAssetsCommand($integrity, $healer, $plans, $fingerprints, $healLog);
    }
}
