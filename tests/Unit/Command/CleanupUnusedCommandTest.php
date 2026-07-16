<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\CleanupUnusedCommand;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetMutationFingerprintService;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(CleanupUnusedCommand::class)]
class CleanupUnusedCommandTest extends TestCase
{
    #[Test]
    public function deprecatedDryRunAliasIsRemoved(): void
    {
        $command = $this->command($this->createMock(UnusedAssetFinderInterface::class));

        self::assertFalse($command->getDefinition()->hasOption('dry-run'));
    }

    #[Test]
    public function byIdsPreviewsByDefault(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::once())->method('previewMutation')->with(7, 'delete')->willReturn(null);
        $finder->expects(self::never())->method('deleteAssets');

        $status = (new CommandTester($this->command($finder)))->execute(['--by-ids' => '7']);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function applyDeleteRequiresSeparateConfirmation(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::never())->method('deleteAssets');

        $status = (new CommandTester($this->command($finder)))->execute([
            '--by-ids' => '7',
            '--apply' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::INVALID, $status);
    }

    #[Test]
    public function confirmedApplyDeletesOnlyTheNamedIds(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::once())->method('deleteAssets')->with([7], ['asset:7' => 'fingerprint-7'])->willReturn(['deleted' => 1, 'failed' => 0, 'errors' => []]);

        $status = (new CommandTester($this->command($finder)))->execute([
            '--by-ids' => '7',
            '--apply' => true,
            '--confirm-delete' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function scanRequiresAnExplicitSelectorOrAll(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::never())->method('countUnused');

        $status = (new CommandTester($this->command($finder)))->execute([]);

        self::assertSame(Command::INVALID, $status);
    }

    #[Test]
    public function invalidDatesAreReportedAsInvalidInput(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->method('countUnused')->willThrowException(new \InvalidArgumentException('Invalid before date filter.'));

        $status = (new CommandTester($this->command($finder)))->execute(['--before' => 'not-a-date']);

        self::assertSame(Command::INVALID, $status);
    }

    #[Test]
    public function invalidResourceLimitsAreRejectedBeforeSelection(): void
    {
        foreach ([
            ['--all' => true, '--batch-size' => 'invalid'],
            ['--all' => true, '--max-assets' => '1001'],
        ] as $input) {
            $finder = $this->createMock(UnusedAssetFinderInterface::class);
            $finder->expects(self::never())->method('countUnused');
            $tester = new CommandTester($this->command($finder));

            self::assertSame(Command::INVALID, $tester->execute($input));
            self::assertStringContainsString('integers between 1 and 1000', $tester->getDisplay());
        }
    }

    #[Test]
    public function emptySelectorSelectionRejectsApplyAsStale(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::once())->method('countUnused')->willReturn(0);
        $finder->expects(self::never())->method('deleteAssets');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::never())->method('claim');
        $tester = new CommandTester($this->command($finder, plans: $plans));

        $status = $tester->execute([
            '--all' => true,
            '--apply' => true,
            '--confirm-delete' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('no longer current', $tester->getDisplay());
    }

    #[Test]
    public function scanApplyAbortsBeforeMutationWhenTheSnapshotIsIncomplete(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->method('countUnused')->willReturn(2);
        $finder->method('findUnused')->willReturn([
            'items' => [['id' => 7]],
            'total' => 2,
            'page' => 1,
            'pages' => 1,
        ]);
        $finder->expects(self::never())->method('deleteAssets');

        $status = (new CommandTester($this->command($finder)))->execute([
            '--all' => true,
            '--apply' => true,
            '--confirm-delete' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::FAILURE, $status);
    }

    #[Test]
    public function scannedPreviewReportsTheCurrentMutationGuardOutcome(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->method('countUnused')->willReturn(1);
        $finder->method('findUnused')->willReturn([
            'items' => [[
                'id' => 7,
                'full_path' => '/uploads/file.pdf',
                'type' => 'document',
                'file_size' => 100,
                'modified_at' => 1_700_000_000,
            ]],
            'total' => 1,
            'page' => 1,
            'pages' => 1,
        ]);
        $finder->expects(self::once())->method('previewMutation')->with(7, 'delete')->willReturn('Asset is locked');

        $tester = new CommandTester($this->command($finder));
        $status = $tester->execute(['--all' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Asset is locked', $tester->getDisplay());
        self::assertStringContainsString('0 would delete', $tester->getDisplay());
        self::assertStringContainsString('1 would be skipped', $tester->getDisplay());
        self::assertStringContainsString('Plan token:', $tester->getDisplay());
    }

    #[Test]
    public function appliedCleanupReturnsFailureAndDoesNotRenderSuccessForSkippedAssets(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->method('deleteAssets')->willReturn([
            'deleted' => 0,
            'failed' => 1,
            'errors' => [7 => 'Asset is locked'],
        ]);

        $tester = new CommandTester($this->command($finder));
        $status = $tester->execute([
            '--by-ids' => '7',
            '--apply' => true,
            '--confirm-delete' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Cleanup complete: 0 deleted, 1 failed out of 1 total.', $tester->getDisplay());
        self::assertStringContainsString('Asset 7: Asset is locked', $tester->getDisplay());
    }

    #[Test]
    public function previewReportsAConcurrentAssetLockWithoutCallingTheFinderGuard(): void
    {
        $store = new InMemoryStore();
        $owner = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        $worker = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        self::assertTrue($owner->acquireAsset(7));

        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::never())->method('previewMutation');

        $tester = new CommandTester($this->command($finder, $worker));
        $status = $tester->execute(['--by-ids' => '7']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Asset is being processed by another job', $tester->getDisplay());
    }

    #[Test]
    public function stalePlanStopsBeforeMutation(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        $finder->expects(self::never())->method('deleteAssets');
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->method('claim')->willReturn(ApplyPlanStatus::Stale);

        $tester = new CommandTester($this->command($finder, plans: $plans));
        $status = $tester->execute([
            '--by-ids' => '7',
            '--apply' => true,
            '--confirm-delete' => true,
            '--plan-token' => 'stale-plan',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('stale or was already used', $tester->getDisplay());
    }

    private function command(UnusedAssetFinderInterface $finder, ?LoopGuard $loopGuard = null, ?ApplyPlanServiceInterface $plans = null): CleanupUnusedCommand
    {
        $fingerprints = $this->createMock(AssetMutationFingerprintService::class);
        $fingerprints->method('fingerprintMap')->willReturnCallback(static function (array $ids): array {
            $result = [];
            foreach ($ids as $id) {
                $result['asset:' . $id] = 'fingerprint-' . $id;
            }

            return $result;
        });

        if ($plans === null) {
            $plans = $this->createMock(ApplyPlanServiceInterface::class);
            $plans->method('issue')->willReturn('signed-plan');
            $plans->method('claim')->willReturn(ApplyPlanStatus::Claimed);
        }

        return new CleanupUnusedCommand(
            $finder,
            $loopGuard ?? new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore())),
            $fingerprints,
            $plans,
        );
    }
}
