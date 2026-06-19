<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\Strategy\IsolateUnreferencedStrategy;
use Oronts\AssetPilotBundle\Service\AssetDependencyResolver;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IsolateUnreferencedStrategy::class)]
class IsolateUnreferencedStrategyTest extends TestCase
{
    #[Test]
    public function isNamedIsolate(): void
    {
        $strategy = new IsolateUnreferencedStrategy(
            $this->createMock(AssetDependencyResolver::class),
            $this->createMock(QuarantineService::class),
        );

        self::assertSame('isolate', $strategy->name());
    }

    #[Test]
    public function quarantinesAnUnreferencedCopy(): void
    {
        $resolver = $this->createMock(AssetDependencyResolver::class);
        $resolver->method('dependentObjectIds')->with(9, 1)->willReturn([]);
        $quarantine = $this->createMock(QuarantineService::class);
        $quarantine->expects(self::once())->method('quarantine')->with([9])
            ->willReturn(['quarantined' => 1, 'failed' => 0, 'errors' => []]);

        $disposition = (new IsolateUnreferencedStrategy($resolver, $quarantine))
            ->disposeCopy(9, new RepointReport(9, 5, 0, []));

        self::assertSame(9, $disposition->copyId);
        self::assertSame(DispositionOutcome::Quarantined, $disposition->outcome);
    }

    #[Test]
    public function leavesAStillReferencedCopy(): void
    {
        $resolver = $this->createMock(AssetDependencyResolver::class);
        $resolver->method('dependentObjectIds')->with(9, 1)->willReturn([42]);
        $quarantine = $this->createMock(QuarantineService::class);
        $quarantine->expects(self::never())->method('quarantine');

        $disposition = (new IsolateUnreferencedStrategy($resolver, $quarantine))
            ->disposeCopy(9, new RepointReport(9, 5, 0, []));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
    }

    #[Test]
    public function reportsAnErrorWhenQuarantineDoesNotMoveTheCopy(): void
    {
        $resolver = $this->createMock(AssetDependencyResolver::class);
        $resolver->method('dependentObjectIds')->with(9, 1)->willReturn([]);
        $quarantine = $this->createMock(QuarantineService::class);
        $quarantine->method('quarantine')->willReturn(['quarantined' => 0, 'failed' => 1, 'errors' => ['nope']]);

        $disposition = (new IsolateUnreferencedStrategy($resolver, $quarantine))
            ->disposeCopy(9, new RepointReport(9, 5, 0, []));

        self::assertSame(DispositionOutcome::LeftError, $disposition->outcome);
    }
}
