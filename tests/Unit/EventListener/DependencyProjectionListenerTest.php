<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\EventListener;

use Oronts\AssetPilotBundle\EventListener\DependencyProjectionListener;
use Oronts\AssetPilotBundle\Message\DependencyProjectionRefreshMessage;
use Oronts\AssetPilotBundle\Model\DependencyExtraction;
use Oronts\AssetPilotBundle\Model\DependencySourceToken;
use Oronts\AssetPilotBundle\Service\AssetDeletionFenceInterface;
use Oronts\AssetPilotBundle\Service\AssetDependencyTargetExtractor;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\DependencyProjectionInterface;
use Oronts\AssetPilotBundle\Service\ProjectionMarkerConnectionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Event\Model\AssetEvent;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\ValidationException;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(DependencyProjectionListener::class)]
class DependencyProjectionListenerTest extends TestCase
{
    #[Test]
    public function marksDirtyBeforeSaveAndRefreshesTheSameRevisionAfterSave(): void
    {
        $asset = $this->asset(17);
        $token = new DependencySourceToken('asset:17', 4);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->expects(self::once())->method('markDirty')->with('asset', 17)->willReturn($token);
        $projection->expects(self::once())->method('refresh')->with($asset, $token)->willReturn(true);
        $listener = $this->buildListener($projection, $this->createMock(MessageBusInterface::class));

        $listener->onPreSave(new AssetEvent($asset));
        $listener->onPostSave(new AssetEvent($asset));
    }

    #[Test]
    public function deferredPublicationRetainsDirtyAndDispatchesInsteadOfRefreshing(): void
    {
        $asset = $this->asset(17);
        $token = new DependencySourceToken('asset:17', 4);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->expects(self::once())->method('markDirty')->with('asset', 17)->willReturn($token);
        $projection->expects(self::once())->method('retainDirtyForCommit')->with('asset', 17, $token)->willReturn($token);
        $projection->expects(self::never())->method('refresh');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $message): bool => $message instanceof DependencyProjectionRefreshMessage
                && $message->sourceType === 'asset'
                && $message->sourceId === 17,
        ))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $listener = $this->buildListener($projection, $bus, null, $this->marker(true));
        $listener->onPreSave(new AssetEvent($asset));
        $listener->onPostSave(new AssetEvent($asset));
    }

    #[Test]
    public function discardsThePendingFenceWhenANewElementSaveFails(): void
    {
        $asset = $this->asset(0);
        $token = new DependencySourceToken('pending:test', 1);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->expects(self::once())->method('markPending')->with('asset')->willReturn($token);
        $projection->expects(self::once())->method('discard')->with($token);
        $listener = $this->buildListener($projection, $this->createMock(MessageBusInterface::class));

        $listener->onPreSave(new AssetEvent($asset));
        $listener->onSaveFailure(new AssetEvent($asset));
    }

    #[Test]
    public function dispatchesARepairWhenConcurrentRevisionFencingRejectsTheRefresh(): void
    {
        $asset = $this->asset(17);
        $token = new DependencySourceToken('asset:17', 2);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturn($token);
        $projection->method('refresh')->willReturn(false);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $message): bool => $message instanceof DependencyProjectionRefreshMessage
                && $message->sourceType === 'asset'
                && $message->sourceId === 17,
        ))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $listener = $this->buildListener($projection, $bus);

        $listener->onPreSave(new AssetEvent($asset));
        $listener->onPostSave(new AssetEvent($asset));
    }

    #[Test]
    public function removesTheProjectedSourceOnlyAfterDeleteSucceeds(): void
    {
        $asset = $this->asset(17);
        $token = new DependencySourceToken('asset:17', 3);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturn($token);
        $projection->expects(self::once())->method('remove')->with('asset', 17, $token);
        $listener = $this->buildListener($projection, $this->createMock(MessageBusInterface::class));

        $listener->onPreDelete(new AssetEvent($asset));
        $listener->onPostDelete(new AssetEvent($asset));
    }

    #[Test]
    public function rejectsASaveThatReferencesAFencedOrMissingAsset(): void
    {
        $asset = $this->referencingAsset(17, [88]);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturn(new DependencySourceToken('asset:17', 1));
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->expects(self::once())->method('assertWritableTargets')->with([88])
            ->willThrowException(new ValidationException('Cannot save: referenced asset 88 is being deleted.'));

        $listener = $this->buildListener($projection, $this->createMock(MessageBusInterface::class), $fence);

        $this->expectException(ValidationException::class);
        $listener->onPreSave(new AssetEvent($asset));
    }

    #[Test]
    public function rejectsASaveWhenDependencyExtractionIsIncomplete(): void
    {
        // An incompletely resolvable object cannot prove which target fences to join, so the save must be
        // blocked fail-closed rather than committing a possibly dangling reference to an asset mid-deletion.
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(42);
        $object->method('resolveDependencies')->willReturn([]);
        $fieldExtractor = $this->createStub(AssetFieldExtractorInterface::class);
        $fieldExtractor->method('classificationStoreAssetIds')->willReturn(new DependencyExtraction([], false));
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->expects(self::once())->method('markDirty')->with('object', 42)->willReturn(new DependencySourceToken('object:42', 1));

        $listener = $this->buildListener($projection, $this->createMock(MessageBusInterface::class), fieldExtractor: $fieldExtractor);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('could not fully resolve');
        $listener->onPreSave(new DataObjectEvent($object));
    }

    #[Test]
    public function proceedsWithAnAssetReferencingSaveRegardlessOfTheAmbientTransaction(): void
    {
        // the listener no longer rejects a save merely because a consumer wraps it in an outer
        // transaction. The projection publishes the marker and edge on a dedicated autocommit connection, so
        // the deletion-fence handshake still holds without breaking the consumer's transaction.
        $asset = $this->referencingAsset(17, [88]);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->expects(self::once())->method('markDirty')->with('asset', 17)->willReturn(new DependencySourceToken('asset:17', 1));
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->expects(self::once())->method('assertWritableTargets')->with([88]);

        $this->buildListener($projection, $this->createMock(MessageBusInterface::class), $fence)
            ->onPreSave(new AssetEvent($asset));
    }

    #[Test]
    public function marksTheSourceDirtyBeforeConsultingTheFence(): void
    {
        $asset = $this->referencingAsset(17, [88]);
        $calls = [];
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturnCallback(function () use (&$calls): DependencySourceToken {
            $calls[] = 'markDirty';

            return new DependencySourceToken('asset:17', 1);
        });
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('assertWritableTargets')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'assertWritableTargets';
        });

        $this->buildListener($projection, $this->createMock(MessageBusInterface::class), $fence)
            ->onPreSave(new AssetEvent($asset));

        self::assertSame(['markDirty', 'assertWritableTargets'], $calls);
    }

    #[Test]
    public function repairsThePersistedDirtyMarkerAfterAFenceRejection(): void
    {
        $asset = $this->referencingAsset(17, [88]);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markDirty')->willReturn(new DependencySourceToken('asset:17', 5));
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('assertWritableTargets')->willThrowException(new ValidationException('referenced asset 88 is being deleted'));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $message): bool => $message instanceof DependencyProjectionRefreshMessage
                && $message->sourceType === 'asset'
                && $message->sourceId === 17,
        ))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $listener = $this->buildListener($projection, $bus, $fence);

        try {
            $listener->onPreSave(new AssetEvent($asset));
            self::fail('expected the fence rejection to throw');
        } catch (ValidationException) {
            // The committed dirty marker must now be repaired by the failure event.
        }

        $listener->onSaveFailure(new AssetEvent($asset));
    }

    #[Test]
    public function discardsThePendingMarkerAfterAFenceRejectionOnANewElement(): void
    {
        $asset = $this->referencingAsset(0, [88]);
        $token = new DependencySourceToken('pending:test', 1);
        $projection = $this->createMock(DependencyProjectionInterface::class);
        $projection->method('markPending')->willReturn($token);
        $projection->expects(self::once())->method('discard')->with($token);
        $fence = $this->createMock(AssetDeletionFenceInterface::class);
        $fence->method('assertWritableTargets')->willThrowException(new ValidationException('referenced asset 88 is being deleted'));
        $listener = $this->buildListener($projection, $this->createMock(MessageBusInterface::class), $fence);

        try {
            $listener->onPreSave(new AssetEvent($asset));
            self::fail('expected the fence rejection to throw');
        } catch (ValidationException) {
            // The pending marker for a never-persisted element must be discarded.
        }

        $listener->onSaveFailure(new AssetEvent($asset));
    }

    private function buildListener(
        DependencyProjectionInterface $projection,
        MessageBusInterface $bus,
        ?AssetDeletionFenceInterface $fence = null,
        ?ProjectionMarkerConnectionInterface $marker = null,
        ?AssetFieldExtractorInterface $fieldExtractor = null,
    ): DependencyProjectionListener {
        return new DependencyProjectionListener(
            $projection,
            $bus,
            new NullLogger(),
            new AssetDependencyTargetExtractor($fieldExtractor ?? $this->createStub(AssetFieldExtractorInterface::class)),
            $fence ?? $this->createMock(AssetDeletionFenceInterface::class),
            $marker ?? $this->marker(false),
        );
    }

    private function marker(bool $deferred): ProjectionMarkerConnectionInterface
    {
        $marker = $this->createMock(ProjectionMarkerConnectionInterface::class);
        $marker->method('publicationIsDeferred')->willReturn($deferred);

        return $marker;
    }

    private function asset(int $id): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn($id);

        return $asset;
    }

    /** @param list<int> $assetTargetIds */
    private function referencingAsset(int $id, array $assetTargetIds): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn($id);
        $asset->method('resolveDependencies')->willReturn(array_map(
            static fn (int $targetId): array => ['id' => $targetId, 'type' => 'asset'],
            $assetTargetIds,
        ));

        return $asset;
    }
}
