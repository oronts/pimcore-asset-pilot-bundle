<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/**
 * The single place that queues an organize message and stamps its deduplication key. Centralised so
 * every producer (the save/upload listeners, the API, the CLI, reorganize and replay) shares one
 * key/TTL policy: if the key formula drifted between call sites, Messenger deduplication would
 * silently stop collapsing redundant jobs. Every message carries a dispatch timestamp so the handler
 * can skip a job for an object changed after it was queued (stale-job guard).
 */
class OrganizeDispatcher
{
    private const float SINGLE_DEDUP_TTL = 30.0;
    private const float BULK_DEDUP_TTL = 60.0;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function dispatchObject(int $objectId, TriggerType $triggerType): void
    {
        $this->messageBus->dispatch(Envelope::wrap(
            new OrganizeAssetsMessage($objectId, $triggerType, $this->now()),
            [new DeduplicateStamp('asset_pilot_organize_' . $objectId, self::SINGLE_DEDUP_TTL)],
        ));
    }

    /**
     * @param int[] $objectIds
     */
    public function dispatchBulk(array $objectIds, TriggerType $triggerType): void
    {
        // Sort so the dedup key is independent of caller-side ordering: the same batch passed as
        // [1,2] and [2,1] must collapse to one queued job.
        $objectIds = array_values($objectIds);
        sort($objectIds);

        $this->messageBus->dispatch(Envelope::wrap(
            new BulkOrganizeMessage($objectIds, $triggerType, $this->now()),
            [new DeduplicateStamp('asset_pilot_bulk_' . md5(implode(',', $objectIds)), self::BULK_DEDUP_TTL)],
        ));
    }

    protected function now(): int
    {
        return time();
    }
}
