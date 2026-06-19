<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\Strategy\RepointAndDeleteStrategy;
use Oronts\AssetPilotBundle\Service\AssetDependencyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(RepointAndDeleteStrategy::class)]
class RepointAndDeleteStrategyTest extends TestCase
{
    /** @param \ArrayObject<int, int> $deleted */
    private function strategy(AssetDependencyResolver $dependencies, \ArrayObject $deleted, bool $deleteResult = true): RepointAndDeleteStrategy
    {
        return new class ($dependencies, $deleted, $deleteResult) extends RepointAndDeleteStrategy {
            /** @param \ArrayObject<int, int> $deleted */
            public function __construct(AssetDependencyResolver $d, private readonly \ArrayObject $deleted, private readonly bool $deleteResult)
            {
                parent::__construct($d, new NullLogger());
            }

            protected function deleteAsset(int $assetId): bool
            {
                $this->deleted->append($assetId);

                return $this->deleteResult;
            }
        };
    }

    #[Test]
    public function isNamedDelete(): void
    {
        self::assertSame('delete', $this->strategy($this->createMock(AssetDependencyResolver::class), new \ArrayObject())->name());
    }

    #[Test]
    public function deletesAFullyRepointedAndNowUnreferencedCopy(): void
    {
        $dependencies = $this->createMock(AssetDependencyResolver::class);
        $dependencies->method('dependentObjectIds')->with(9, 1)->willReturn([]);
        $deleted = new \ArrayObject();

        $disposition = $this->strategy($dependencies, $deleted)->disposeCopy(9, new RepointReport(9, 5, 2, []));

        self::assertSame(DispositionOutcome::Deleted, $disposition->outcome);
        self::assertSame([9], $deleted->getArrayCopy());
    }

    #[Test]
    public function leavesACopyWhoseReferencesWereNotFullyRepointed(): void
    {
        $deleted = new \ArrayObject();
        $disposition = $this->strategy($this->createMock(AssetDependencyResolver::class), $deleted)
            ->disposeCopy(9, new RepointReport(9, 5, 0, ['object 1 still references the copy']));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
        self::assertSame([], $deleted->getArrayCopy(), 'a blocked copy is never deleted');
    }

    #[Test]
    public function refusesToDeleteWhenAReferenceReappearedAfterRepoint(): void
    {
        $dependencies = $this->createMock(AssetDependencyResolver::class);
        $dependencies->method('dependentObjectIds')->with(9, 1)->willReturn([42]);
        $deleted = new \ArrayObject();

        $disposition = $this->strategy($dependencies, $deleted)->disposeCopy(9, new RepointReport(9, 5, 2, []));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function reportsAnErrorWhenTheDeleteFails(): void
    {
        $dependencies = $this->createMock(AssetDependencyResolver::class);
        $dependencies->method('dependentObjectIds')->with(9, 1)->willReturn([]);
        $deleted = new \ArrayObject();

        $disposition = $this->strategy($dependencies, $deleted, deleteResult: false)->disposeCopy(9, new RepointReport(9, 5, 2, []));

        self::assertSame(DispositionOutcome::LeftError, $disposition->outcome);
    }
}
