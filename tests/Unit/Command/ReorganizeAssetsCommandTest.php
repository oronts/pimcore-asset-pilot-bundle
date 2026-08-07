<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\ReorganizeAssetsCommand;
use Oronts\AssetPilotBundle\Command\Support\ReviewedSelectionConsolePresenter;
use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Enum\OperationRunKind;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\ReviewedSelectionError;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\ReviewedSelectionException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\ReviewedSelectionResult;
use Oronts\AssetPilotBundle\Service\AssetReorganizer;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ReorganizeAssetsCommand::class)]
final class ReorganizeAssetsCommandTest extends TestCase
{
    #[Test]
    public function folderSelectionPreviewPrintsSignedExactPlan(): void
    {
        $selector = $this->createMock(AssetReorganizer::class);
        $selector->expects(self::once())->method('selectFolder')->with('/Staging', 100)->willReturn([
            'assetCount' => 2,
            'objectIds' => [11],
            'truncated' => false,
        ]);
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::once())->method('execute')->with(
            OperationRunKind::Reorganize,
            [11],
            ['folder' => '/Staging', 'limit' => 100, 'mode' => 'folder'],
            TriggerType::Manual,
            true,
            false,
            null,
            self::callback(static fn (ActorContext $actor): bool => $actor->type === ActorType::System),
        )->willReturn(new ReviewedSelectionResult(
            true,
            'signed-plan',
            null,
            null,
            1,
            0,
            0,
            0,
            0,
            [$this->operation()],
        ));

        $tester = new CommandTester(new ReorganizeAssetsCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter()));
        $status = $tester->execute(['--folder' => '/Staging']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('signed-plan', $tester->getDisplay());
        self::assertStringContainsString('/target/image.jpg', $tester->getDisplay());
        self::assertStringContainsString('Preview complete; no assets were changed.', $tester->getDisplay());
    }

    #[Test]
    public function applyWithoutPlanTokenIsRejectedBeforeSelection(): void
    {
        $selector = $this->createMock(AssetReorganizer::class);
        $selector->expects(self::never())->method('selectAssets');
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::never())->method('execute');

        $tester = new CommandTester(new ReorganizeAssetsCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter()));
        $status = $tester->execute(['--by-ids' => '5,6', '--apply' => true]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('--plan-token', $tester->getDisplay());
    }

    #[Test]
    public function asyncApplyPassesTokenAndPrintsQueuedRun(): void
    {
        $selector = $this->createMock(AssetReorganizer::class);
        $selector->expects(self::once())->method('selectAssets')->with([5, 6])->willReturn([
            'assetCount' => 2,
            'objectIds' => [10, 12],
            'truncated' => false,
        ]);
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::once())->method('execute')->with(
            OperationRunKind::Reorganize,
            [10, 12],
            ['assetIds' => [5, 6], 'mode' => 'asset_ids'],
            TriggerType::Manual,
            false,
            true,
            'signed-plan',
            self::isInstanceOf(ActorContext::class),
        )->willReturn(new ReviewedSelectionResult(
            false,
            null,
            'reorganize-run',
            OperationRunStatus::Queued,
            2,
            0,
            2,
            0,
            0,
        ));

        $tester = new CommandTester(new ReorganizeAssetsCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter()));
        $status = $tester->execute([
            '--by-ids' => '6,5',
            '--async' => true,
            '--apply' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('reorganize-run', $tester->getDisplay());
        self::assertStringContainsString('queued', $tester->getDisplay());
        self::assertStringContainsString('Reorganize queued.', $tester->getDisplay());
    }

    #[Test]
    public function stalePlanReturnsFailure(): void
    {
        $selector = $this->createMock(AssetReorganizer::class);
        $selector->method('selectFolder')->willReturn(['assetCount' => 1, 'objectIds' => [11], 'truncated' => false]);
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->method('execute')->willThrowException(new ReviewedSelectionException(
            ReviewedSelectionError::StalePlan,
            'The preview plan is stale.',
        ));

        $tester = new CommandTester(new ReorganizeAssetsCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter()));
        $status = $tester->execute([
            '--folder' => '/Staging',
            '--apply' => true,
            '--plan-token' => 'stale',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('stale', $tester->getDisplay());
    }

    #[Test]
    public function malformedOrUnboundedLimitsAreRejectedBeforeSelection(): void
    {
        foreach (['invalid', '0', '1001', '1.5'] as $limit) {
            $selector = $this->createMock(AssetReorganizer::class);
            $selector->expects(self::never())->method('selectFolder');
            $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
            $reviewed->expects(self::never())->method('execute');
            $tester = new CommandTester(new ReorganizeAssetsCommand($selector, $reviewed, new ReviewedSelectionConsolePresenter()));

            self::assertSame(Command::INVALID, $tester->execute(['--folder' => '/Staging', '--limit' => $limit]));
            self::assertStringContainsString('between 1 and 1000', $tester->getDisplay());
        }
    }

    private function operation(): MoveOperation
    {
        return new MoveOperation(
            5,
            '/source/image.jpg',
            '/target/image.jpg',
            11,
            'Product',
            'images',
            OperationStatus::Pending,
            TriggerType::Manual,
        );
    }
}
