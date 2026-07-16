<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\OrganizeCommand;
use Oronts\AssetPilotBundle\Command\Support\ReviewedSelectionConsolePresenter;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\OperationRunStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ReviewedSelectionResult;
use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\ReviewedObjectOperationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(OrganizeCommand::class)]
final class OrganizeCommandTest extends TestCase
{
    #[Test]
    public function previewsOneObjectAndPrintsItsSignedPlan(): void
    {
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::once())->method('execute')->with(
            'organize',
            [42],
            ['mode' => 'object_id', 'objectId' => 42],
            TriggerType::Manual,
            true,
            false,
            null,
            ActorContext::system(),
        )->willReturn(new ReviewedSelectionResult(true, 'signed-plan', null, null, 1, 0, 0, 0, 0));

        $tester = new CommandTester($this->command($reviewed));
        $exit = $tester->execute(['--object-id' => '42']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('signed-plan', $tester->getDisplay());
        self::assertStringContainsString('Preview complete', $tester->getDisplay());
    }

    #[Test]
    public function appliesTheExactClassSelectionWithItsPlanToken(): void
    {
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::once())->method('execute')->with(
            'organize',
            [1, 2, 3],
            ['class' => 'Product', 'mode' => 'class'],
            TriggerType::BulkOperation,
            false,
            true,
            'signed-plan',
            ActorContext::system(),
        )->willReturn(new ReviewedSelectionResult(
            false,
            null,
            str_repeat('a', 32),
            OperationRunStatus::Queued,
            3,
            0,
            3,
            0,
            0,
        ));

        $tester = new CommandTester($this->command($reviewed));
        $exit = $tester->execute([
            '--class' => 'Product',
            '--batch-size' => '2',
            '--apply' => true,
            '--plan-token' => 'signed-plan',
            '--async' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Organization queued', $tester->getDisplay());
        self::assertStringContainsString(str_repeat('a', 32), $tester->getDisplay());
    }

    #[Test]
    public function applyRequiresAPlanAndPreviewRejectsAPlan(): void
    {
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::never())->method('execute');
        $command = $this->command($reviewed);

        $apply = new CommandTester($command);
        self::assertSame(Command::INVALID, $apply->execute(['--object-id' => '42', '--apply' => true]));
        self::assertStringContainsString('--plan-token', $apply->getDisplay());

        $preview = new CommandTester($command);
        self::assertSame(Command::INVALID, $preview->execute(['--object-id' => '42', '--plan-token' => 'unexpected']));
        self::assertStringContainsString('only valid together with --apply', $preview->getDisplay());
    }

    #[Test]
    public function retiredDryRunOptionIsRemoved(): void
    {
        self::assertFalse($this->command($this->createMock(ReviewedObjectOperationServiceInterface::class))->getDefinition()->hasOption('dry-run'));
    }

    #[Test]
    public function rejectsConflictingSelectorsAndInvalidNumericOptions(): void
    {
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::never())->method('execute');
        $command = $this->command($reviewed);

        $both = new CommandTester($command);
        self::assertSame(Command::FAILURE, $both->execute(['--class' => 'Product', '--object-id' => '42']));

        $objectId = new CommandTester($command);
        self::assertSame(Command::FAILURE, $objectId->execute(['--object-id' => 'invalid']));

        $batchSize = new CommandTester($command);
        self::assertSame(Command::FAILURE, $batchSize->execute(['--class' => 'Product', '--batch-size' => '0']));
    }

    #[Test]
    public function abortsWhenTheClassSelectionChangesDuringSnapshotCollection(): void
    {
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::never())->method('execute');
        $command = $this->command($reviewed, batches: [[1, 2]]);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--class' => 'Product']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('selection changed', $tester->getDisplay());
    }

    #[Test]
    public function refusesAnUnboundedClassSelection(): void
    {
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::never())->method('execute');
        $command = $this->command($reviewed, total: 1_001);

        $tester = new CommandTester($command);
        self::assertSame(Command::INVALID, $tester->execute(['--class' => 'Product']));
        self::assertStringContainsString('maximum', $tester->getDisplay());
        self::assertStringContainsString('1000', $tester->getDisplay());
    }

    #[Test]
    public function emptyClassSelectionRejectsApplyAsStale(): void
    {
        $reviewed = $this->createMock(ReviewedObjectOperationServiceInterface::class);
        $reviewed->expects(self::never())->method('execute');
        $tester = new CommandTester($this->command($reviewed, total: 0, batches: []));

        $status = $tester->execute([
            '--class' => 'Product',
            '--apply' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('no longer current', $tester->getDisplay());
    }

    /** @param list<list<int>> $batches */
    private function command(ReviewedObjectOperationServiceInterface $reviewed, int $total = 3, array $batches = [[1, 2], [3]]): OrganizeCommand
    {
        return new class (
            $reviewed,
            new ReviewedSelectionConsolePresenter(),
            $this->createMock(RuleEngineInterface::class),
            $this->createMock(AssetFieldExtractorInterface::class),
            $this->createMock(NamingStrategyInterface::class),
            $total,
            $batches,
        ) extends OrganizeCommand {
            /** @param list<list<int>> $batches */
            public function __construct(
                ReviewedObjectOperationServiceInterface $reviewed,
                ReviewedSelectionConsolePresenter $presenter,
                RuleEngineInterface $ruleEngine,
                AssetFieldExtractorInterface $fieldExtractor,
                NamingStrategyInterface $namingStrategy,
                private readonly int $total,
                private readonly array $batches,
            ) {
                parent::__construct($reviewed, $presenter, $ruleEngine, $fieldExtractor, $namingStrategy);
            }

            protected function countObjectsForClass(string $className): int
            {
                return $this->total;
            }

            protected function objectIdBatches(string $className, int $batchSize): \Generator
            {
                foreach ($this->batches as $batch) {
                    yield $batch;
                }
            }
        };
    }
}
