<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Merge\Strategy;

use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Exception\AssetDeletionFenceLostException;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\Strategy\RepointAndDeleteStrategy;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetDeletionFenceInterface;
use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use Oronts\AssetPilotBundle\Service\DependencyUsageVerifierInterface;
use Oronts\AssetPilotBundle\Tests\Unit\Merge\MergeContextStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(RepointAndDeleteStrategy::class)]
class RepointAndDeleteStrategyTest extends TestCase
{
    private function passingFence(): AssetDeletionFenceInterface
    {
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('acquire')->willReturn('token');

        return $fence;
    }

    /** @param \ArrayObject<int, int> $deleted */
    private function strategy(bool $hasReferences, \ArrayObject $deleted, bool $deleteResult = true, bool $deletionAllowed = true, bool $protected = false, ?AssetDeletionFenceInterface $fence = null): RepointAndDeleteStrategy
    {
        return new class (
            $hasReferences,
            $deleted,
            $deleteResult,
            $deletionAllowed,
            $protected,
            $this->createMock(ElementAuthorization::class),
            $this->createMock(DependencyUsageVerifierInterface::class),
            $this->createMock(ContentUsageScanner::class),
            $fence ?? $this->passingFence(),
        ) extends RepointAndDeleteStrategy {
            /** @param \ArrayObject<int, int> $deleted */
            public function __construct(private readonly bool $hasReferences, private readonly \ArrayObject $deleted, private readonly bool $deleteResult, private readonly bool $deletionAllowed, private readonly bool $protected, ElementAuthorization $authorization, DependencyUsageVerifierInterface $dependencyVerifier, ContentUsageScanner $contentScanner, AssetDeletionFenceInterface $deletionFence)
            {
                parent::__construct(new NullLogger(), $authorization, $dependencyVerifier, $contentScanner, $deletionFence);
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

            protected function isProtected(int $assetId): bool
            {
                return $this->protected;
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
    public function leavesTheCopyWhenAnotherOperationOwnsTheDeletionFence(): void
    {
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('acquire')->willReturn(null);
        $deleted = new \ArrayObject();

        $disposition = $this->strategy(false, $deleted, fence: $fence)->disposeCopy(new RepointReport(9, 5, 2, []), new MergeContextStub(9));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
        self::assertStringContainsString('being deleted by another operation', (string) $disposition->reason);
        self::assertCount(0, $deleted);
    }

    #[Test]
    public function abortsAndReleasesWhenTheFenceIsLostBeforeDeletion(): void
    {
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('acquire')->willReturn('token');
        $fence->method('refreshOrFail')->willThrowException(new AssetDeletionFenceLostException(9));
        $fence->expects(self::once())->method('release')->with(9, 'token');
        $deleted = new \ArrayObject();

        try {
            $this->strategy(false, $deleted, fence: $fence)->disposeCopy(new RepointReport(9, 5, 2, []), new MergeContextStub(9));
            self::fail('expected the lost fence to abort the disposal');
        } catch (AssetDeletionFenceLostException) {
            // The copy is never deleted, and the fence is still released on the abort.
        }

        self::assertCount(0, $deleted);
    }

    #[Test]
    public function deletesAFullyRepointedAndNowUnreferencedCopy(): void
    {
        $deleted = new \ArrayObject();

        $disposition = $this->strategy(false, $deleted)->disposeCopy(new RepointReport(9, 5, 2, []), new MergeContextStub(9));

        self::assertSame(DispositionOutcome::Deleted, $disposition->outcome);
        self::assertSame([9], $deleted->getArrayCopy());
    }

    #[Test]
    public function aThrowingFenceReleaseStillReportsTheSuccessfulDeletion(): void
    {
        $deleted = new \ArrayObject();
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('acquire')->willReturn('token');
        $fence->method('release')->willThrowException(new \RuntimeException('fence release failed'));

        $disposition = $this->strategy(false, $deleted, fence: $fence)->disposeCopy(new RepointReport(9, 5, 2, []), new MergeContextStub(9));

        self::assertSame(DispositionOutcome::Deleted, $disposition->outcome);
        self::assertSame([9], $deleted->getArrayCopy());
    }

    #[Test]
    public function neverDeletesAProtectedCopy(): void
    {
        $deleted = new \ArrayObject();

        $disposition = $this->strategy(false, $deleted, protected: true)
            ->disposeCopy(new RepointReport(9, 5, 2, []), new MergeContextStub(9));

        self::assertSame(DispositionOutcome::LeftError, $disposition->outcome);
        self::assertStringContainsString('protected', (string) $disposition->reason);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function leavesACopyWhoseReferencesWereNotFullyRepointed(): void
    {
        $deleted = new \ArrayObject();
        $disposition = $this->strategy(false, $deleted)
            ->disposeCopy(new RepointReport(9, 5, 0, ['object 1 still references the copy']), new MergeContextStub(9));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
        self::assertSame([], $deleted->getArrayCopy(), 'a blocked copy is never deleted');
    }

    #[Test]
    public function refusesToDeleteWhenAReferenceOfAnyTypeReappearedAfterRepoint(): void
    {
        $deleted = new \ArrayObject();

        $disposition = $this->strategy(true, $deleted)->disposeCopy(new RepointReport(9, 5, 2, []), new MergeContextStub(9));

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

        $disposition = $this->strategy(false, $deleted, deleteResult: false)->disposeCopy(new RepointReport(9, 5, 2, []), new MergeContextStub(9));

        self::assertSame(DispositionOutcome::LeftError, $disposition->outcome);
    }

    #[Test]
    public function leavesACopyTheCurrentUserMayNotDelete(): void
    {
        $deleted = new \ArrayObject();

        $disposition = $this->strategy(false, $deleted, deletionAllowed: false)->disposeCopy(new RepointReport(9, 5, 2, []), new MergeContextStub(9));

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
            $this->createMock(DependencyUsageVerifierInterface::class),
            $content,
            $asset,
            $this->passingFence(),
        ) extends RepointAndDeleteStrategy {
            public function __construct(NullLogger $logger, ElementAuthorization $authorization, DependencyUsageVerifierInterface $dependencies, ContentUsageScanner $content, private readonly Asset $asset, AssetDeletionFenceInterface $deletionFence)
            {
                parent::__construct($logger, $authorization, $dependencies, $content, $deletionFence);
            }

            protected function loadAsset(int $assetId): Asset
            {
                return $this->asset;
            }

            protected function deleteAsset(int $assetId): bool
            {
                TestCase::fail('An unverifiable asset must never be deleted.');
            }
        };

        $disposition = $strategy->disposeCopy(new RepointReport(9, 5, 1, []), new MergeContextStub(9));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
        self::assertStringContainsString('not configured', (string) $disposition->reason);
    }

    #[Test]
    public function refusesHardDeleteForAHardCodedContentReference(): void
    {
        $content = $this->createMock(ContentUsageScanner::class);
        $content->method('canVerify')->willReturn(true);
        $content->method('freshlyReferencedInContent')->willReturn(true);
        $dependencies = $this->createMock(DependencyUsageVerifierInterface::class);
        $dependencies->method('verdict')->willReturn(DependencyUsageVerdict::Safe);
        $asset = $this->createMock(Asset::class);
        $strategy = new class (
            new NullLogger(),
            $this->createMock(ElementAuthorization::class),
            $dependencies,
            $content,
            $asset,
            $this->passingFence(),
        ) extends RepointAndDeleteStrategy {
            public function __construct(NullLogger $logger, ElementAuthorization $authorization, DependencyUsageVerifierInterface $dependencies, ContentUsageScanner $content, private readonly Asset $asset, AssetDeletionFenceInterface $deletionFence)
            {
                parent::__construct($logger, $authorization, $dependencies, $content, $deletionFence);
            }

            protected function loadAsset(int $assetId): Asset
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

        $disposition = $strategy->disposeCopy(new RepointReport(9, 5, 1, []), new MergeContextStub(9));

        self::assertSame(DispositionOutcome::LeftReferenced, $disposition->outcome);
        self::assertStringContainsString('hard-coded content reference', (string) $disposition->reason);
    }
}
