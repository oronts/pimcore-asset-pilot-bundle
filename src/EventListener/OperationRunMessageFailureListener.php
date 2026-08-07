<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\EventListener;

use Oronts\AssetPilotBundle\Enum\OperationRunItemStatus;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener(priority: 0)]
final class OperationRunMessageFailureListener
{
    public function __construct(
        private readonly OperationRunStoreInterface $runs,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if ($message instanceof OrganizeAssetsMessage && $message->runId !== null) {
            $this->failItems($message->runId, [$message->objectId], $message, $event->getThrowable());

            return;
        }
        if ($message instanceof BulkOrganizeMessage && $message->runId !== null) {
            $this->failItems($message->runId, $message->objectIds, $message, $event->getThrowable());
        }
    }

    /** @param list<int> $objectIds */
    private function failItems(string $runId, array $objectIds, object $message, \Throwable $failure): void
    {
        $this->logger->error('Asset Pilot: message handling exhausted all retries', [
            'run_id' => $runId,
            'message_type' => $message::class,
            'exception' => $failure,
        ]);

        foreach (array_values(array_unique($objectIds)) as $objectId) {
            try {
                $this->runs->completeItem(
                    $runId,
                    $this->itemKey($objectId),
                    OperationRunItemStatus::Failed,
                    error: 'Message handling exhausted all retries.',
                );
            } catch (\Throwable $storeException) {
                $this->logStoreFailure($runId, $objectId, $storeException);
            }
        }

        try {
            $this->runs->finish($runId);
        } catch (\Throwable $storeException) {
            $this->logStoreFailure($runId, null, $storeException);
        }
    }

    private function logStoreFailure(string $runId, ?int $objectId, \Throwable $exception): void
    {
        $this->logger->error('Asset Pilot: exhausted message state could not be persisted', [
            'run_id' => $runId,
            'object_id' => $objectId,
            'exception' => $exception,
        ]);
    }

    private function itemKey(int $objectId): string
    {
        return 'object:' . $objectId;
    }
}
