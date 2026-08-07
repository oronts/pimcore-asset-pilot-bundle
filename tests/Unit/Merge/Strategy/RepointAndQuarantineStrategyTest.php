<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\Strategy\RepointAndQuarantineStrategy;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use Oronts\AssetPilotBundle\Tests\Unit\Merge\MergeContextStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RepointAndQuarantineStrategy::class)]
class RepointAndQuarantineStrategyTest extends TestCase
{
    #[Test]
    public function isNamedQuarantine(): void
    {
        self::assertSame('quarantine', (new RepointAndQuarantineStrategy($this->createMock(QuarantineService::class)))->name());
    }

    #[Test]
    public function quarantinesAFullyRepointedCopy(): void
    {
        $quarantine = $this->createMock(QuarantineService::class);
        $quarantine->expects(self::once())->method('quarantine')->with([9])
            ->willReturn(['quarantined' => 1, 'failed' => 0, 'errors' => []]);

        $disposition = (new RepointAndQuarantineStrategy($quarantine))
            ->disposeCopy(new RepointReport(9, 5, 2, []), new MergeContextStub(9));

        self::assertSame(DispositionOutcome::Quarantined, $disposition->outcome);
    }

    #[Test]
    public function leavesACopyWhoseReferencesWereNotFullyRepointed(): void
    {
        $quarantine = $this->createMock(QuarantineService::class);
        $quarantine->expects(self::never())->method('quarantine');

        $disposition = (new RepointAndQuarantineStrategy($quarantine))
            ->disposeCopy(new RepointReport(9, 5, 1, ['document 12 references the copy']), new MergeContextStub(9));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
        self::assertStringContainsString('document 12', $disposition->reason);
    }

    #[Test]
    public function recoversAQuarantineThatCommittedBeforeTheRunItemCompleted(): void
    {
        $quarantine = $this->createMock(QuarantineService::class);
        $quarantine->expects(self::once())->method('recoverQuarantine')->with(9)->willReturn(true);

        $disposition = (new RepointAndQuarantineStrategy($quarantine))
            ->recoverDisposition(9, new RepointReport(9, 5, 2, []));

        self::assertNotNull($disposition);
        self::assertSame(DispositionOutcome::Quarantined, $disposition->outcome);
    }

    #[Test]
    public function reportsAnErrorWhenQuarantineDoesNotMoveTheCopy(): void
    {
        $quarantine = $this->createMock(QuarantineService::class);
        $quarantine->method('quarantine')->willReturn(['quarantined' => 0, 'failed' => 1, 'errors' => ['locked']]);

        $disposition = (new RepointAndQuarantineStrategy($quarantine))
            ->disposeCopy(new RepointReport(9, 5, 2, []), new MergeContextStub(9));

        self::assertSame(DispositionOutcome::LeftError, $disposition->outcome);
    }
}
