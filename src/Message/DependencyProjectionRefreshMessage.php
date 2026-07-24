<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Message;

readonly class DependencyProjectionRefreshMessage
{
    /**
     * @param int|null    $expectedRevision    the source modification timestamp the owner is committing; the
     *                                          handler refuses to mark the projection clean until the persisted
     *                                          source reaches it, so an early delivery (dispatched before the
     *                                          outer transaction commits) can never certify the old committed
     *                                          state as current
     * @param string|null $expectedFingerprint hash of the asset edges the owner is committing; because the
     *                                          Pimcore modification date is second-resolution it cannot tell two
     *                                          same-second saves apart, so the handler certifies clean only when
     *                                          the visible edges match this content fingerprint
     */
    public function __construct(
        public string $sourceType,
        public int $sourceId,
        public ?int $expectedRevision = null,
        public ?string $expectedFingerprint = null,
    ) {
        if (!in_array($sourceType, ['object', 'document', 'asset'], true) || $sourceId <= 0) {
            throw new \InvalidArgumentException('A dependency refresh message requires a supported source type and positive ID.');
        }
    }
}
