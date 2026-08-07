<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\RecoverOperationsCommand;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Exception\OperationRecoveryPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\OperationRecoveryResult;
use Oronts\AssetPilotBundle\Model\ReviewedOperationRecovery;
use Oronts\AssetPilotBundle\Service\OperationRecoveryCoordinatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RecoverOperationsCommand::class)]
final class RecoverOperationsCommandTest extends TestCase
{
    #[Test]
    public function previewUsesAnExactSystemPlanWithoutRecovering(): void
    {
        $recovery = $this->createMock(OperationRecoveryCoordinatorInterface::class);
        $recovery->expects(self::once())->method('preview')->with(
            25,
            self::callback(static fn (ActorContext $actor): bool => $actor->type === ActorType::System),
        )->willReturn(new ReviewedOperationRecovery([
            $this->recoveryResult(OperationStatus::Completed, false),
        ], 'signed-token', false));
        $recovery->expects(self::never())->method('apply');

        $tester = new CommandTester(new RecoverOperationsCommand($recovery));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '25']));
        self::assertStringContainsString('signed-token', $tester->getDisplay());
        self::assertStringContainsString('No asset mutation', $tester->getDisplay());
    }

    #[Test]
    public function applyUsesTheMatchingSystemPlan(): void
    {
        $recovery = $this->createMock(OperationRecoveryCoordinatorInterface::class);
        $recovery->expects(self::once())->method('apply')->with(
            100,
            self::callback(static fn (ActorContext $actor): bool => $actor->type === ActorType::System),
            'signed-token',
        )->willReturn(new ReviewedOperationRecovery([
            $this->recoveryResult(OperationStatus::Completed, true),
        ], null, true));

        $tester = new CommandTester(new RecoverOperationsCommand($recovery));

        self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true, '--plan-token' => 'signed-token']));
        self::assertStringContainsString('Resolved 1 stale operation', $tester->getDisplay());
    }

    #[Test]
    public function applyRejectsAChangedScope(): void
    {
        $recovery = $this->createMock(OperationRecoveryCoordinatorInterface::class);
        $recovery->method('apply')->willThrowException(new OperationRecoveryPlanException(ApplyPlanStatus::Stale));

        $tester = new CommandTester(new RecoverOperationsCommand($recovery));

        self::assertSame(Command::INVALID, $tester->execute(['--apply' => true, '--plan-token' => 'stale']));
        self::assertStringContainsString('scope changed', $tester->getDisplay());
    }

    #[Test]
    public function unresolvedRecoveryReturnsFailure(): void
    {
        $recovery = $this->createMock(OperationRecoveryCoordinatorInterface::class);
        $recovery->method('apply')->willReturn(new ReviewedOperationRecovery([
            $this->recoveryResult(OperationStatus::RecoveryRequired, true),
        ], null, true));

        $tester = new CommandTester(new RecoverOperationsCommand($recovery));

        self::assertSame(Command::FAILURE, $tester->execute(['--apply' => true, '--plan-token' => 'token']));
        self::assertStringContainsString('still require recovery', $tester->getDisplay());
    }

    #[Test]
    public function emptyPreviewSucceedsWithoutAPlan(): void
    {
        $recovery = $this->createMock(OperationRecoveryCoordinatorInterface::class);
        $recovery->method('preview')->willReturn(new ReviewedOperationRecovery([], null, false));

        $tester = new CommandTester(new RecoverOperationsCommand($recovery));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No stale operations', $tester->getDisplay());
    }

    #[Test]
    public function invalidLimitsAreRejectedWithoutReviewOrApply(): void
    {
        foreach (['not-a-number', '0', '1001'] as $limit) {
            $recovery = $this->createMock(OperationRecoveryCoordinatorInterface::class);
            $recovery->expects(self::never())->method('preview');
            $recovery->expects(self::never())->method('apply');
            $tester = new CommandTester(new RecoverOperationsCommand($recovery));

            self::assertSame(Command::INVALID, $tester->execute(['--limit' => $limit]));
            self::assertStringContainsString('integer between 1 and 1000', $tester->getDisplay());
        }
    }

    private function recoveryResult(OperationStatus $status, bool $updated): OperationRecoveryResult
    {
        return new OperationRecoveryResult(91, 7, OperationKind::Move, $status, $updated, 'classification', str_repeat('a', 64));
    }
}
