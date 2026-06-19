<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\MergeDuplicatesCommand;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\MergeOutcome;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MergeDuplicatesCommand::class)]
class MergeDuplicatesCommandTest extends TestCase
{
    private function tester(DuplicateDetectionService $detection, DuplicateMergeService $merge): CommandTester
    {
        return new CommandTester(new MergeDuplicatesCommand($detection, $merge));
    }

    private function detectionReturning(?DuplicateGroup $group): DuplicateDetectionService
    {
        $detection = $this->createMock(DuplicateDetectionService::class);
        $detection->method('groupForChecksum')->willReturn($group);

        return $detection;
    }

    private function mergeService(): DuplicateMergeService
    {
        $merge = $this->createMock(DuplicateMergeService::class);
        $merge->method('availableStrategies')->willReturn(['quarantine', 'delete', 'isolate']);

        return $merge;
    }

    #[Test]
    public function previewsByDefaultWithoutMerging(): void
    {
        $merge = $this->mergeService();
        $merge->expects(self::once())->method('merge')
            ->with(self::isInstanceOf(DuplicateGroup::class), null, null, true)
            ->willReturn(new MergeOutcome('abc', 3, [new CopyDisposition(9, DispositionOutcome::Skipped, 'dry run: would repoint and dispose')]));

        $tester = $this->tester($this->detectionReturning(new DuplicateGroup('abc', 100, 2, [3, 9])), $merge);
        $exit = $tester->execute(['--checksum' => 'abc']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('preview', strtolower($tester->getDisplay()));
    }

    #[Test]
    public function appliesTheMergeWithApply(): void
    {
        $merge = $this->mergeService();
        $merge->expects(self::once())->method('merge')
            ->with(self::isInstanceOf(DuplicateGroup::class), 9, 'delete', false)
            ->willReturn(new MergeOutcome('abc', 9, [new CopyDisposition(3, DispositionOutcome::Deleted)]));

        $tester = $this->tester($this->detectionReturning(new DuplicateGroup('abc', 100, 2, [3, 9])), $merge);
        $exit = $tester->execute(['--checksum' => 'abc', '--apply' => true, '--canonical' => '9', '--strategy' => 'delete']);

        self::assertSame(Command::SUCCESS, $exit);
    }

    #[Test]
    public function failsWhenNoGroupExistsForTheChecksum(): void
    {
        $tester = $this->tester($this->detectionReturning(null), $this->mergeService());
        $exit = $tester->execute(['--checksum' => 'missing']);

        self::assertSame(Command::FAILURE, $exit);
    }

    #[Test]
    public function rejectsAnUnknownStrategyBeforeTouchingData(): void
    {
        $merge = $this->mergeService();
        $merge->expects(self::never())->method('merge');

        $tester = $this->tester($this->detectionReturning(new DuplicateGroup('abc', 100, 2, [3, 9])), $merge);
        $exit = $tester->execute(['--checksum' => 'abc', '--strategy' => 'nope']);

        self::assertSame(Command::INVALID, $exit);
    }

    #[Test]
    public function requiresAChecksum(): void
    {
        $tester = $this->tester($this->detectionReturning(null), $this->mergeService());
        $exit = $tester->execute([]);

        self::assertSame(Command::INVALID, $exit);
    }
}
