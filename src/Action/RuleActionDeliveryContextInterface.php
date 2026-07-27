<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Action;

interface RuleActionDeliveryContextInterface
{
    public function deliveryId(): string;

    public function attempt(): int;

    public function heartbeat(): void;
}
