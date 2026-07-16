<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\MessageHandler;

use Oronts\AssetPilotBundle\Message\OperationDeliveryMessage;
use Oronts\AssetPilotBundle\Service\OperationDeliveryProcessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class OperationDeliveryHandler
{
    public function __construct(private readonly OperationDeliveryProcessor $processor) {}

    public function __invoke(OperationDeliveryMessage $message): void
    {
        $this->processor->process($message->deliveryId);
    }
}
