<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\RetryDeliveriesCommand;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Exception\DeliveryRetryPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\DeadOperationDelivery;
use Oronts\AssetPilotBundle\Model\ReviewedDeliveryRetry;
use Oronts\AssetPilotBundle\Service\OperationDeliveryRetryCoordinatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RetryDeliveriesCommand::class)]
final class RetryDeliveriesCommandTest extends TestCase
{
    #[Test]
    public function previewUsesSystemActorAndDoesNotApply(): void
    {
        $retries = $this->createMock(OperationDeliveryRetryCoordinatorInterface::class);
        $retries->expects(self::once())->method('preview')->with(
            25,
            self::callback(static fn (ActorContext $actor): bool => $actor->type === ActorType::System),
        )->willReturn(new ReviewedDeliveryRetry([$this->delivery()], 'signed-token', false));
        $retries->expects(self::never())->method('apply');

        $tester = new CommandTester(new RetryDeliveriesCommand($retries));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '25']));
        self::assertStringContainsString('signed-token', $tester->getDisplay());
        self::assertStringContainsString('No delivery state was changed', $tester->getDisplay());
    }

    #[Test]
    public function applyRequeuesTheMatchingSystemPlan(): void
    {
        $retries = $this->createMock(OperationDeliveryRetryCoordinatorInterface::class);
        $retries->expects(self::once())->method('apply')->with(
            100,
            self::callback(static fn (ActorContext $actor): bool => $actor->type === ActorType::System),
            'signed-token',
        )->willReturn(new ReviewedDeliveryRetry([$this->delivery()], null, true));

        $tester = new CommandTester(new RetryDeliveriesCommand($retries));

        self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true, '--plan-token' => 'signed-token']));
        self::assertStringContainsString('Requeued 1 dead operation delivery', $tester->getDisplay());
    }

    #[Test]
    public function applyRejectsAChangedScope(): void
    {
        $retries = $this->createMock(OperationDeliveryRetryCoordinatorInterface::class);
        $retries->method('apply')->willThrowException(new DeliveryRetryPlanException(ApplyPlanStatus::Stale));

        $tester = new CommandTester(new RetryDeliveriesCommand($retries));

        self::assertSame(Command::INVALID, $tester->execute(['--apply' => true, '--plan-token' => 'stale']));
        self::assertStringContainsString('scope changed', $tester->getDisplay());
    }

    #[Test]
    public function emptyPreviewSucceedsWithoutAPlan(): void
    {
        $retries = $this->createMock(OperationDeliveryRetryCoordinatorInterface::class);
        $retries->method('preview')->willReturn(new ReviewedDeliveryRetry([], null, false));

        $tester = new CommandTester(new RetryDeliveriesCommand($retries));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No dead operation deliveries', $tester->getDisplay());
    }

    #[Test]
    public function invalidLimitsAreRejectedWithoutReviewOrApply(): void
    {
        foreach (['not-a-number', '0', '1001'] as $limit) {
            $retries = $this->createMock(OperationDeliveryRetryCoordinatorInterface::class);
            $retries->expects(self::never())->method('preview');
            $retries->expects(self::never())->method('apply');
            $tester = new CommandTester(new RetryDeliveriesCommand($retries));

            self::assertSame(Command::INVALID, $tester->execute(['--limit' => $limit]));
            self::assertStringContainsString('integer between 1 and 1000', $tester->getDisplay());
        }
    }

    private function delivery(): DeadOperationDelivery
    {
        return new DeadOperationDelivery(
            str_repeat('d', 64),
            91,
            'observer:success',
            'observer',
            OperationDeliveryOutcome::Success,
            5,
            'unavailable',
            '2026-07-15 10:00:00',
            str_repeat('f', 64),
        );
    }
}
