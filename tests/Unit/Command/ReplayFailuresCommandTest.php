<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\ReplayFailuresCommand;
use Oronts\AssetPilotBundle\Command\Support\ReviewedSelectionConsolePresenter;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\ReviewedSelectionError;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\ReviewedSelectionException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ReviewedSelectionResult;
use Oronts\AssetPilotBundle\Service\FailureReplayService;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ReplayFailuresCommand::class)]
final class ReplayFailuresCommandTest extends TestCase
{
    #[Test]
    public function explicitObjectsPreviewPrintsSignedPlan(): void
    {
        $selector = $this->createMock(FailureReplayService::class);
        $selector->expects(self::once())->method('selectObjects')->with(['object_ids' => [42]], 100)->willReturn([42]);
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::once())->method('execute')->with(
            'replay',
            [42],
            ['filters' => ['object_ids' => [42]], 'limit' => 100],
            TriggerType::Manual,
            true,
            false,
            null,
            self::callback(static fn (ActorContext $actor): bool => $actor->type === ActorType::System),
        )->willReturn(new ReviewedSelectionResult(true, 'signed-replay', null, null, 1, 0, 0, 0, 0));

        $tester = new CommandTester(new ReplayFailuresCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter()));
        $status = $tester->execute(['--object-id' => '42']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('signed-replay', $tester->getDisplay());
        self::assertStringContainsString('Preview complete; no assets were changed.', $tester->getDisplay());
    }

    #[Test]
    public function applyWithoutPlanTokenIsRejectedBeforeAuditSelection(): void
    {
        $selector = $this->createMock(FailureReplayService::class);
        $selector->expects(self::never())->method('selectObjects');
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::never())->method('execute');

        $tester = new CommandTester(new ReplayFailuresCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter()));
        $status = $tester->execute(['--apply' => true]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('--plan-token', $tester->getDisplay());
    }

    #[Test]
    public function asyncApplyPrintsCorrelatableRun(): void
    {
        $selector = $this->createMock(FailureReplayService::class);
        $selector->expects(self::once())->method('selectObjects')->with([], 100)->willReturn([42, 43]);
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::once())->method('execute')->with(
            'replay',
            [42, 43],
            ['filters' => [], 'limit' => 100],
            TriggerType::Manual,
            false,
            true,
            'signed-replay',
            self::isInstanceOf(ActorContext::class),
        )->willReturn(new ReviewedSelectionResult(
            false,
            null,
            'replay-run',
            OperationRunStatus::Queued,
            2,
            0,
            2,
            0,
            0,
        ));

        $tester = new CommandTester(new ReplayFailuresCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter()));
        $status = $tester->execute([
            '--async' => true,
            '--apply' => true,
            '--plan-token' => 'signed-replay',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('replay-run', $tester->getDisplay());
        self::assertStringContainsString('queued', $tester->getDisplay());
        self::assertStringContainsString('Replay queued.', $tester->getDisplay());
    }

    #[Test]
    public function combinesObjectIdsWithAllAuditSelectors(): void
    {
        $filters = [
            'object_ids' => [42, 43],
            'since' => '2026-07-15 10:00:00',
            'rule_name' => 'images',
            'object_class' => 'Product',
        ];
        $selector = $this->createMock(FailureReplayService::class);
        $selector->expects(self::once())->method('selectObjects')->with($filters, 25)->willReturn([42]);
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::once())->method('execute')->with(
            'replay',
            [42],
            ['filters' => $filters, 'limit' => 25],
            TriggerType::Manual,
            true,
            false,
            null,
            self::isInstanceOf(ActorContext::class),
        )->willReturn(new ReviewedSelectionResult(true, 'plan', null, null, 1, 0, 0, 0, 0));

        $status = (new CommandTester(new ReplayFailuresCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter())))->execute([
            '--object-id' => '43,42',
            '--since' => '2026-07-15 10:00:00',
            '--rule' => 'images',
            '--class' => 'Product',
            '--limit' => '25',
        ]);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function malformedTokenReturnsInvalid(): void
    {
        $selector = $this->createMock(FailureReplayService::class);
        $selector->method('selectObjects')->willReturn([42]);
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->method('execute')->willThrowException(new ReviewedSelectionException(
            ReviewedSelectionError::MalformedPlanToken,
            'The plan token is malformed.',
        ));

        $tester = new CommandTester(new ReplayFailuresCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter()));
        $status = $tester->execute(['--apply' => true, '--plan-token' => 'broken']);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('malformed', $tester->getDisplay());
    }

    #[Test]
    public function invalidDatesFailBeforeTheServiceIsCalled(): void
    {
        $selector = $this->createMock(FailureReplayService::class);
        $selector->expects(self::never())->method('selectObjects');
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::never())->method('execute');

        $status = (new CommandTester(new ReplayFailuresCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter())))->execute([
            '--object-id' => '42',
            '--since' => 'not a date',
        ]);

        self::assertSame(Command::INVALID, $status);
    }

    #[Test]
    public function malformedOrUnboundedLimitsAreRejectedBeforeSelection(): void
    {
        foreach (['invalid', '0', '1001', '1.5'] as $limit) {
            $selector = $this->createMock(FailureReplayService::class);
            $selector->expects(self::never())->method('selectObjects');
            $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
            $reviewed->expects(self::never())->method('execute');
            $tester = new CommandTester(new ReplayFailuresCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter()));

            self::assertSame(Command::INVALID, $tester->execute(['--limit' => $limit]));
            self::assertStringContainsString('between 1 and 1000', $tester->getDisplay());
        }
    }
}
