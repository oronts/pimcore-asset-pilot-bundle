<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\RebuildDependencyProjectionCommand;
use Oronts\AssetPilotBundle\Enum\DependencyProjectionState;
use Oronts\AssetPilotBundle\Model\DependencyProjectionBatchResult;
use Oronts\AssetPilotBundle\Model\DependencyProjectionStatus;
use Oronts\AssetPilotBundle\Service\DependencyProjectionRebuilderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RebuildDependencyProjectionCommand::class)]
class RebuildDependencyProjectionCommandTest extends TestCase
{
    #[Test]
    public function runsOneBoundedBatchAndReportsThePersistedCursor(): void
    {
        $rebuilder = $this->createMock(DependencyProjectionRebuilderInterface::class);
        $rebuilder->expects(self::once())
            ->method('rebuildBatch')
            ->with(25, false)
            ->willReturn(new DependencyProjectionBatchResult(
                25,
                0,
                false,
                new DependencyProjectionStatus(DependencyProjectionState::Building, 2, 0, 25, 40, 'object', 25, null, null, null),
            ));
        $tester = new CommandTester(new RebuildDependencyProjectionCommand($rebuilder, 25));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('object:25', $tester->getDisplay());
        self::assertStringContainsString('Rerun', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAnUnboundedLimitBeforeCallingTheService(): void
    {
        $rebuilder = $this->createMock(DependencyProjectionRebuilderInterface::class);
        $rebuilder->expects(self::never())->method('rebuildBatch');
        $tester = new CommandTester(new RebuildDependencyProjectionCommand($rebuilder, 1000));

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '10001']));
    }
}
