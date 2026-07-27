<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Action;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

interface RuleActionInterface
{
    public function getType(): string;

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public function prepare(Asset $asset, AbstractObject $object, array $config): array;

    /**
     * @param array<string, mixed> $payload
     *
     * Use the delivery ID as the idempotency key. Long-running actions must call heartbeat()
     * while working so another worker cannot reclaim the delivery lease.
     */
    public function applyPrepared(Asset $asset, array $payload, RuleActionDeliveryContextInterface $delivery): void;
}
