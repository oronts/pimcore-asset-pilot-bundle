<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched for asset mutations outside the move pipeline (lock/unlock, bulk tag/property, unused
 * delete/move, revert), so a consumer can hook every asset change from one surface (CDN purge,
 * search reindex, DAM sync). The dispatched event name identifies the mutation.
 */
class AssetMutationEvent extends Event
{
    /**
     * @param int[]                $assetIds the assets actually affected
     * @param array<string, mixed> $context  mutation-specific detail (e.g. target folder, property name)
     */
    public function __construct(
        public readonly array $assetIds,
        public readonly string $mutation,
        public readonly array $context = [],
    ) {}
}
