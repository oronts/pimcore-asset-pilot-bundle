<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\MessageHandler;

use Oronts\AssetPilotBundle\Message\OperationDeliveryMessage;
use Oronts\AssetPilotBundle\Service\OperationDeliveryProcessorInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class OperationDeliveryHandler
{
    public function __construct(private readonly OperationDeliveryProcessorInterface $processor) {}

    public function __invoke(OperationDeliveryMessage $message): void
    {
        $this->processor->process($message->deliveryId);
    }
}
