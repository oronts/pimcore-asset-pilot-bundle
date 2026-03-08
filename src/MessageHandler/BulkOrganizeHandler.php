<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\MessageHandler;

use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class BulkOrganizeHandler
{
    public function __construct(
        protected readonly AssetOrganizer $organizer,
        protected readonly LoggerInterface $logger,
    ) {}

    public function __invoke(BulkOrganizeMessage $message): void
    {
        $this->logger->info('Asset Pilot: processing bulk organization for {count} objects (trigger: {trigger})', [
            'count' => count($message->objectIds),
            'trigger' => $message->triggerType->value,
        ]);

        try {
            $results = $this->organizer->organizeBulk($message->objectIds, $message->triggerType);

            $this->logger->info('Asset Pilot: bulk organization complete - {count} operations', [
                'count' => count($results),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Asset Pilot: bulk organization failed: {error}', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
