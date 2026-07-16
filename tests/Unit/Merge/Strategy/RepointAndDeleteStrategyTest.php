<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\Strategy\RepointAndDeleteStrategy;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use Oronts\AssetPilotBundle\Service\DependencyUsageScannerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(RepointAndDeleteStrategy::class)]
class RepointAndDeleteStrategyTest extends TestCase
{
    /** @param \ArrayObject<int, int> $deleted */
    private function strategy(bool $hasReferences, \ArrayObject $deleted, bool $deleteResult = true, bool $deletionAllowed = true): RepointAndDeleteStrategy
    {
        return new class (
            $hasReferences,
            $deleted,
            $deleteResult,
            $deletionAllowed,
            $this->createMock(ElementAuthorization::class),
            $this->createMock(DependencyUsageScannerInterface::class),
            $this->createMock(ContentUsageScanner::class),
        ) extends RepointAndDeleteStrategy {
            /** @param \ArrayObject<int, int> $deleted */
            public function __construct(private readonly bool $hasReferences, private readonly \ArrayObject $deleted, private readonly bool $deleteResult, private readonly bool $deletionAllowed, ElementAuthorization $authorization, DependencyUsageScannerInterface $dependencyScanner, ContentUsageScanner $contentScanner)
            {
                parent::__construct(new NullLogger(), $authorization, $dependencyScanner, $contentScanner);
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return null;
            }

            protected function referenceBlockReason(int $assetId): ?string
            {
                return $this->hasReferences ? 'a live dependency exists after the repoint; not deleting' : null;
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
    public function recoversADeleteThatCommittedBeforeTheRunItemCompleted(): void
    {
        $disposition = $this->strategy(false, new \ArrayObject())
            ->recoverDisposition(9, new RepointReport(9, 5, 2, []));

        self::assertNotNull($disposition);
        self::assertSame(DispositionOutcome::Deleted, $disposition->outcome);
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

    #[Test]
    public function refusesHardDeleteWhenContentVerificationIsUnavailable(): void
    {
        $content = $this->createMock(ContentUsageScanner::class);
        $content->method('canVerify')->willReturn(false);
        $asset = $this->createMock(Asset::class);
        $strategy = new class (
            new NullLogger(),
            $this->createMock(ElementAuthorization::class),
            $this->createMock(DependencyUsageScannerInterface::class),
            $content,
            $asset,
        ) extends RepointAndDeleteStrategy {
            public function __construct(NullLogger $logger, ElementAuthorization $authorization, DependencyUsageScannerInterface $dependencies, ContentUsageScanner $content, private readonly Asset $asset)
            {
                parent::__construct($logger, $authorization, $dependencies, $content);
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $this->asset;
            }

            protected function deleteAsset(int $assetId): bool
            {
                TestCase::fail('An unverifiable asset must never be deleted.');
            }
        };

        $disposition = $strategy->disposeCopy(9, new RepointReport(9, 5, 1, []));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
        self::assertStringContainsString('not configured', (string) $disposition->reason);
    }

    #[Test]
    public function refusesHardDeleteForAHardCodedContentReference(): void
    {
        $content = $this->createMock(ContentUsageScanner::class);
        $content->method('canVerify')->willReturn(true);
        $content->method('isReferencedInContent')->willReturn(true);
        $dependencies = $this->createMock(DependencyUsageScannerInterface::class);
        $dependencies->method('isReferenced')->willReturn(false);
        $asset = $this->createMock(Asset::class);
        $strategy = new class (
            new NullLogger(),
            $this->createMock(ElementAuthorization::class),
            $dependencies,
            $content,
            $asset,
        ) extends RepointAndDeleteStrategy {
            public function __construct(NullLogger $logger, ElementAuthorization $authorization, DependencyUsageScannerInterface $dependencies, ContentUsageScanner $content, private readonly Asset $asset)
            {
                parent::__construct($logger, $authorization, $dependencies, $content);
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $this->asset;
            }

            protected function hasReferences(int $assetId): bool
            {
                return false;
            }

            protected function deleteAsset(int $assetId): bool
            {
                TestCase::fail('A content-referenced asset must never be deleted.');
            }
        };

        $disposition = $strategy->disposeCopy(9, new RepointReport(9, 5, 1, []));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
        self::assertStringContainsString('hard-coded content reference', (string) $disposition->reason);
    }
}
