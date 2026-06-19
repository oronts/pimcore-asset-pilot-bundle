<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use Oronts\AssetPilotBundle\Service\DuplicateReferenceRepointer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DuplicateMergeService::class)]
class DuplicateMergeServiceTest extends TestCase
{
    /** @param \ArrayObject<int, int>|null $disposed records copy ids handed to the strategy */
    private function strategy(string $name, ?\ArrayObject $disposed = null): DuplicateMergeStrategyInterface
    {
        return new class ($name, $disposed) implements DuplicateMergeStrategyInterface {
            public function __construct(private readonly string $n, private readonly ?\ArrayObject $disposed) {}

            public function name(): string
            {
                return $this->n;
            }

            public function disposeCopy(int $copyId, RepointReport $report): CopyDisposition
            {
                $this->disposed?->append($copyId);

                return new CopyDisposition($copyId, DispositionOutcome::Quarantined);
            }
        };
    }

    /**
     * @param list<DuplicateMergeStrategyInterface> $strategies
     */
    private function service(DuplicateReferenceRepointer $repointer, array $strategies): DuplicateMergeService
    {
        return new DuplicateMergeService($strategies, $repointer, new NullLogger(), 'quarantine');
    }

    #[Test]
    public function repointsEveryCopyOntoTheLowestIdCanonicalAndDisposesThem(): void
    {
        $disposed = new \ArrayObject();
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        // Canonical is 3 (lowest id); copies 7 and 9 are each repointed onto it.
        $repointer->expects(self::exactly(2))->method('repoint')
            ->willReturnCallback(static fn (int $from, int $to, bool $dry): RepointReport => new RepointReport($from, $to, 1, []));

        $group = new DuplicateGroup('abc', 100, 3, [7, 3, 9]);
        $outcome = $this->service($repointer, [$this->strategy('quarantine', $disposed)])->merge($group);

        self::assertSame(3, $outcome->canonicalId);
        self::assertSame([7, 9], $disposed->getArrayCopy());
        self::assertCount(2, $outcome->dispositions);
        self::assertSame(DispositionOutcome::Quarantined, $outcome->dispositions[0]->outcome);
    }

    #[Test]
    public function honoursAnExplicitCanonicalWhenItIsAMemberOfTheGroup(): void
    {
        $disposed = new \ArrayObject();
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->method('repoint')->willReturnCallback(static fn (int $from, int $to, bool $dry): RepointReport => new RepointReport($from, $to, 0, []));

        $group = new DuplicateGroup('abc', 100, 3, [7, 3, 9]);
        $outcome = $this->service($repointer, [$this->strategy('quarantine', $disposed)])->merge($group, canonicalId: 9);

        self::assertSame(9, $outcome->canonicalId);
        self::assertSame([7, 3], $disposed->getArrayCopy());
    }

    #[Test]
    public function dryRunRepointsAndDisposesNothingButReportsThePlan(): void
    {
        $disposed = new \ArrayObject();
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->method('repoint')->willReturnCallback(static fn (int $from, int $to, bool $dry): RepointReport => new RepointReport($from, $to, 1, []));

        $group = new DuplicateGroup('abc', 100, 2, [3, 9]);
        $outcome = $this->service($repointer, [$this->strategy('quarantine', $disposed)])->merge($group, dryRun: true);

        self::assertSame([], $disposed->getArrayCopy(), 'a dry run must not dispose any copy');
        self::assertSame(DispositionOutcome::Skipped, $outcome->dispositions[0]->outcome);
    }

    #[Test]
    public function returnsAnEmptyOutcomeWhenTheGroupIsSingular(): void
    {
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::never())->method('repoint');

        $outcome = $this->service($repointer, [$this->strategy('quarantine')])->merge(new DuplicateGroup('abc', 100, 1, [5]));

        self::assertSame(0, $outcome->canonicalId);
        self::assertSame([], $outcome->dispositions);
    }

    #[Test]
    public function rejectsAnUnknownStrategyName(): void
    {
        $service = $this->service($this->createMock(DuplicateReferenceRepointer::class), [$this->strategy('quarantine')]);

        $this->expectException(\InvalidArgumentException::class);
        $service->merge(new DuplicateGroup('abc', 100, 2, [1, 2]), null, 'nope');
    }

    #[Test]
    public function rejectsACanonicalThatIsNotAMemberOfTheGroup(): void
    {
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        $repointer->expects(self::never())->method('repoint');
        $service = $this->service($repointer, [$this->strategy('quarantine')]);

        $this->expectException(\InvalidArgumentException::class);
        $service->merge(new DuplicateGroup('abc', 100, 2, [3, 9]), canonicalId: 999);
    }

    #[Test]
    public function advertisesEveryRegisteredStrategyName(): void
    {
        $service = $this->service(
            $this->createMock(DuplicateReferenceRepointer::class),
            [$this->strategy('quarantine'), $this->strategy('delete'), $this->strategy('isolate')],
        );

        self::assertSame(['quarantine', 'delete', 'isolate'], $service->availableStrategies());
    }

    #[Test]
    public function reportsTheConfiguredDefaultStrategyName(): void
    {
        $service = $this->service($this->createMock(DuplicateReferenceRepointer::class), [$this->strategy('quarantine')]);

        self::assertSame('quarantine', $service->defaultStrategyName());
    }
}
