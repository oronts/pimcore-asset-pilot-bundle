<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\MessageHandler;

use Oronts\AssetPilotBundle\Message\DependencyProjectionRefreshMessage;
use Oronts\AssetPilotBundle\Service\AssetDependencyTargetExtractorInterface;
use Oronts\AssetPilotBundle\Service\DependencyProjectionInterface;
use Pimcore\Model\Element\AbstractElement;
use Pimcore\Model\Element\Service as ElementService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DependencyProjectionRefreshHandler
{
    public function __construct(
        private readonly DependencyProjectionInterface $projection,
        private readonly AssetDependencyTargetExtractorInterface $targetExtractor,
    ) {}

    public function __invoke(DependencyProjectionRefreshMessage $message): void
    {
        $token = $this->projection->markDirty($message->sourceType, $message->sourceId);
        $source = $this->loadSource($message->sourceType, $message->sourceId);
        if ($source === null) {
            // Absence is deletion evidence only when no revision is expected (the delete path). A save that
            // expects a committed source it cannot see yet is pre-commit, so retry instead of erasing edges.
            if ($message->expectedRevision !== null) {
                throw new \RuntimeException(sprintf(
                    'Dependency projection refresh for %s:%d cannot see the expected committed source yet; retrying after commit.',
                    $message->sourceType,
                    $message->sourceId,
                ));
            }
            $this->projection->remove($message->sourceType, $message->sourceId, $token);

            return;
        }

        if ($message->expectedRevision !== null && (int) $source->getModificationDate() < $message->expectedRevision) {
            // The owner transaction has not committed the expected revision yet. Refuse to certify the old
            // committed edges as clean (a phantom-clean projection); the source stays dirty and the throw
            // re-queues this refresh, which succeeds once the new state is visible. A rollback that never
            // reaches the revision is cleaned by the reconcile sweeper from the retained dirty row.
            throw new \RuntimeException(sprintf(
                'Dependency projection refresh for %s:%d is ahead of the committed source revision; retrying after commit.',
                $message->sourceType,
                $message->sourceId,
            ));
        }

        if ($message->expectedFingerprint !== null && $this->targetExtractor->fingerprint($source) !== $message->expectedFingerprint) {
            // The visible edges are not the committed intent. A source already past the expected revision was
            // saved again and its own message reconciles it (discard); otherwise the owner has not committed the
            // intended same-second edges yet, so retry until it does.
            if ($message->expectedRevision !== null && (int) $source->getModificationDate() > $message->expectedRevision) {
                return;
            }
            throw new \RuntimeException(sprintf(
                'Dependency projection refresh for %s:%d sees pre-commit content; retrying after commit.',
                $message->sourceType,
                $message->sourceId,
            ));
        }

        if (!$this->projection->refresh($source, $token)) {
            throw new \RuntimeException(sprintf('Dependency projection refresh for %s:%d lost its revision fence.', $message->sourceType, $message->sourceId));
        }
    }

    protected function loadSource(string $sourceType, int $sourceId): ?AbstractElement
    {
        return ElementService::getElementById($sourceType, $sourceId, ['force' => true]);
    }
}
