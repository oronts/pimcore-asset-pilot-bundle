<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\Strategy\RepointAndDeleteStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(RepointAndDeleteStrategy::class)]
class RepointAndDeleteStrategyTest extends TestCase
{
    /** @param \ArrayObject<int, int> $deleted */
    private function strategy(bool $hasReferences, \ArrayObject $deleted, bool $deleteResult = true, bool $deletionAllowed = true): RepointAndDeleteStrategy
    {
        return new class ($hasReferences, $deleted, $deleteResult, $deletionAllowed) extends RepointAndDeleteStrategy {
            /** @param \ArrayObject<int, int> $deleted */
            public function __construct(private readonly bool $hasReferences, private readonly \ArrayObject $deleted, private readonly bool $deleteResult, private readonly bool $deletionAllowed)
            {
                parent::__construct(new NullLogger());
            }

            protected function hasReferences(int $assetId): bool
            {
                return $this->hasReferences;
            }

            protected function isDeletionAllowed(int $assetId): bool
            {
                return $this->deletionAllowed;
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
        self::assertSame('delete', $this->strategy(false, new \ArrayObject())->name());
    }

    #[Test]
    public function deletesAFullyRepointedAndNowUnreferencedCopy(): void
    {
        $deleted = new \ArrayObject();

        $disposition = $this->strategy(false, $deleted)->disposeCopy(9, new RepointReport(9, 5, 2, []));

        self::assertSame(DispositionOutcome::Deleted, $disposition->outcome);
        self::assertSame([9], $deleted->getArrayCopy());
    }

    #[Test]
    public function leavesACopyWhoseReferencesWereNotFullyRepointed(): void
    {
        $deleted = new \ArrayObject();
        $disposition = $this->strategy(false, $deleted)
            ->disposeCopy(9, new RepointReport(9, 5, 0, ['object 1 still references the copy']));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
        self::assertSame([], $deleted->getArrayCopy(), 'a blocked copy is never deleted');
    }

    #[Test]
    public function refusesToDeleteWhenAReferenceOfAnyTypeReappearedAfterRepoint(): void
    {
        $deleted = new \ArrayObject();

        $disposition = $this->strategy(true, $deleted)->disposeCopy(9, new RepointReport(9, 5, 2, []));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function reportsAnErrorWhenTheDeleteFails(): void
    {
        $deleted = new \ArrayObject();

        $disposition = $this->strategy(false, $deleted, deleteResult: false)->disposeCopy(9, new RepointReport(9, 5, 2, []));

        self::assertSame(DispositionOutcome::LeftError, $disposition->outcome);
    }

    #[Test]
    public function leavesACopyTheCurrentUserMayNotDelete(): void
    {
        $deleted = new \ArrayObject();

        $disposition = $this->strategy(false, $deleted, deletionAllowed: false)->disposeCopy(9, new RepointReport(9, 5, 2, []));

        self::assertSame(DispositionOutcome::LeftError, $disposition->outcome);
        self::assertSame([], $deleted->getArrayCopy(), 'a copy the user cannot delete is never deleted');
        self::assertStringContainsString('permitted', (string) $disposition->reason);
    }
}
