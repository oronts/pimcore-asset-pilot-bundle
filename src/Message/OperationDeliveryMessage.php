<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Message;

readonly class OperationDeliveryMessage
{
    public function __construct(public string $deliveryId)
    {
        if ($deliveryId === '') {
            throw new \InvalidArgumentException('An operation delivery message requires a delivery ID.');
        }
    }
}
