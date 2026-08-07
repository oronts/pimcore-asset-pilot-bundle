<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Observer;

use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\PreparedDelivery;

interface DurableOperationObserverInterface
{
    public function id(): string;

    /** Return a Pimcore asset permission, or null when delivery uses persisted metadata only. */
    public function requiredAssetPermission(): ?string;

    /**
     * This method runs before the asset mutation and must not change external state.
     *
     * @return iterable<PreparedDelivery>
     */
    public function prepare(OperationIntent $intent): iterable;

    /** Long-running observers must call $delivery->heartbeat() before irreversible work. */
    public function deliver(DeliveryEnvelope $delivery): void;
}
