<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\EventListener;

use Oronts\AssetPilotBundle\Message\DependencyProjectionRefreshMessage;
use Oronts\AssetPilotBundle\Model\DependencySourceToken;
use Oronts\AssetPilotBundle\Service\AssetDeletionFenceInterface;
use Oronts\AssetPilotBundle\Service\AssetDependencyTargetExtractorInterface;
use Oronts\AssetPilotBundle\Service\DependencyProjectionInterface;
use Oronts\AssetPilotBundle\Service\ProjectionMarkerConnectionInterface;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\DocumentEvents;
use Pimcore\Event\Model\AssetEvent;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Event\Model\DocumentEvent;
use Pimcore\Model\Element\AbstractElement;
use Pimcore\Model\Element\Service as ElementService;
use Pimcore\Model\Element\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class DependencyProjectionListener implements EventSubscriberInterface
{
    /** @var array<int, list<DependencySourceToken>> */
    private array $tokens = [];

    public function __construct(
        private readonly DependencyProjectionInterface $projection,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly AssetDependencyTargetExtractorInterface $targetExtractor,
        private readonly AssetDeletionFenceInterface $fence,
        private readonly ProjectionMarkerConnectionInterface $marker,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            AssetEvents::PRE_ADD => 'onPreSave',
            AssetEvents::PRE_UPDATE => 'onPreSave',
            AssetEvents::POST_ADD => 'onPostSave',
            AssetEvents::POST_UPDATE => 'onPostSave',
            AssetEvents::POST_ADD_FAILURE => 'onSaveFailure',
            AssetEvents::POST_UPDATE_FAILURE => 'onSaveFailure',
            AssetEvents::PRE_DELETE => 'onPreDelete',
            AssetEvents::POST_DELETE => 'onPostDelete',
            AssetEvents::POST_DELETE_FAILURE => 'onDeleteFailure',
            DataObjectEvents::PRE_ADD => 'onPreSave',
            DataObjectEvents::PRE_UPDATE => 'onPreSave',
            DataObjectEvents::POST_ADD => 'onPostSave',
            DataObjectEvents::POST_UPDATE => 'onPostSave',
            DataObjectEvents::POST_ADD_FAILURE => 'onSaveFailure',
            DataObjectEvents::POST_UPDATE_FAILURE => 'onSaveFailure',
            DataObjectEvents::PRE_DELETE => 'onPreDelete',
            DataObjectEvents::POST_DELETE => 'onPostDelete',
            DataObjectEvents::POST_DELETE_FAILURE => 'onDeleteFailure',
            DocumentEvents::PRE_ADD => 'onPreSave',
            DocumentEvents::PRE_UPDATE => 'onPreSave',
            DocumentEvents::POST_ADD => 'onPostSave',
            DocumentEvents::POST_UPDATE => 'onPostSave',
            DocumentEvents::POST_ADD_FAILURE => 'onSaveFailure',
            DocumentEvents::POST_UPDATE_FAILURE => 'onSaveFailure',
            DocumentEvents::PRE_DELETE => 'onPreDelete',
            DocumentEvents::POST_DELETE => 'onPostDelete',
            DocumentEvents::POST_DELETE_FAILURE => 'onDeleteFailure',
        ];
    }

    public function onPreSave(AssetEvent|DataObjectEvent|DocumentEvent $event): void
    {
        if ($this->isVersionOnly($event)) {
            return;
        }

        $element = $event->getElement();
        $sourceType = ElementService::getElementType($element);
        $extraction = $this->targetExtractor->extract($element);

        $token = (int) $element->getId() > 0
            ? $this->projection->markDirty($sourceType, (int) $element->getId())
            : $this->projection->markPending($sourceType);
        $this->tokens[spl_object_id($element)][] = $token;

        // The dirty marker is published (and committed, via the projection's marker connection even inside a
        // consumer transaction) before the fence check, so a concurrent deleter's snapshot cannot miss it.
        if ($extraction->targetIds !== []) {
            $this->fence->assertWritableTargets($extraction->targetIds);
        }

        // Fail closed on an incomplete extraction: we cannot enumerate every referenced asset, so we cannot
        // prove this save joins the active deletion fence of an asset it references. Rejecting the save is the
        // only way to keep the writer/deleter handshake sound; the known targets above are still checked first.
        if (!$extraction->complete) {
            $this->logger->warning('DependencyProjectionListener: blocking save of {type} {id}; dependency extraction was incomplete (an asset reference could not be fully resolved).', [
                'type' => $sourceType,
                'id' => $element->getId(),
            ]);

            throw new ValidationException('Asset Pilot could not fully resolve the asset references on this element (a classification-store value could not be read). The save is blocked to avoid referencing an asset that may be mid-deletion. Fix the unreadable field and retry.');
        }
    }

    public function onPostSave(AssetEvent|DataObjectEvent|DocumentEvent $event): void
    {
        if ($this->isVersionOnly($event)) {
            return;
        }

        $element = $event->getElement();
        $sourceType = ElementService::getElementType($element);
        $sourceId = (int) $element->getId();
        $token = $this->popToken($element) ?? $this->projection->markDirty($sourceType, $sourceId);
        $expectedRevision = (int) $element->getModificationDate();
        $expectedFingerprint = $this->targetExtractor->fingerprint($element);

        if ($this->marker->publicationIsDeferred()) {
            try {
                $this->projection->retainDirtyForCommit($sourceType, $sourceId, $token);
            } catch (\Throwable $e) {
                $this->logger->error('Asset Pilot: dependency projection deferral failed after an element save.', [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'exception' => $e,
                ]);
            }
            $this->dispatchRefresh($sourceType, $sourceId, $expectedRevision, $expectedFingerprint);

            return;
        }

        try {
            if (!$this->projection->refresh($element, $token)) {
                $this->dispatchRefresh($sourceType, $sourceId, $expectedRevision, $expectedFingerprint);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: dependency projection refresh failed after an element save.', [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'exception' => $e,
            ]);
            $this->dispatchRefresh($sourceType, $sourceId, $expectedRevision, $expectedFingerprint);
        }
    }

    public function onSaveFailure(AssetEvent|DataObjectEvent|DocumentEvent $event): void
    {
        if ($this->isVersionOnly($event)) {
            return;
        }

        $element = $event->getElement();
        $token = $this->popToken($element);
        if ($token === null) {
            return;
        }
        if ((int) $element->getId() <= 0 || str_starts_with($token->sourceKey, 'pending:')) {
            $this->projection->discard($token);

            return;
        }

        $this->dispatchRefresh(ElementService::getElementType($element), (int) $element->getId());
    }

    public function onPreDelete(AssetEvent|DataObjectEvent|DocumentEvent $event): void
    {
        $element = $event->getElement();
        $this->tokens[spl_object_id($element)][] = $this->projection->markDirty(
            ElementService::getElementType($element),
            (int) $element->getId(),
        );
    }

    public function onPostDelete(AssetEvent|DataObjectEvent|DocumentEvent $event): void
    {
        $element = $event->getElement();
        $this->projection->remove(
            ElementService::getElementType($element),
            (int) $element->getId(),
            $this->popToken($element),
        );
    }

    public function onDeleteFailure(AssetEvent|DataObjectEvent|DocumentEvent $event): void
    {
        $element = $event->getElement();
        $this->popToken($element);
        $this->dispatchRefresh(ElementService::getElementType($element), (int) $element->getId());
    }

    private function popToken(AbstractElement $element): ?DependencySourceToken
    {
        $key = spl_object_id($element);
        $stack = $this->tokens[$key] ?? [];
        $token = array_pop($stack);
        if ($stack === []) {
            unset($this->tokens[$key]);
        } else {
            $this->tokens[$key] = $stack;
        }

        return $token instanceof DependencySourceToken ? $token : null;
    }

    private function dispatchRefresh(string $sourceType, int $sourceId, ?int $expectedRevision = null, ?string $expectedFingerprint = null): void
    {
        try {
            $this->bus->dispatch(new DependencyProjectionRefreshMessage($sourceType, $sourceId, $expectedRevision, $expectedFingerprint));
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: dependency projection retry could not be dispatched.', [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'exception' => $e,
            ]);
        }
    }

    private function isVersionOnly(AssetEvent|DataObjectEvent|DocumentEvent $event): bool
    {
        return $event->hasArgument('saveVersionOnly') && (bool) $event->getArgument('saveVersionOnly');
    }
}
