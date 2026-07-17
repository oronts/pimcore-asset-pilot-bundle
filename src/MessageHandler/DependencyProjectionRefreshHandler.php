<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\MessageHandler;

use Oronts\AssetPilotBundle\Message\DependencyProjectionRefreshMessage;
use Oronts\AssetPilotBundle\Service\DependencyProjectionInterface;
use Pimcore\Model\Element\AbstractElement;
use Pimcore\Model\Element\Service as ElementService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DependencyProjectionRefreshHandler
{
    public function __construct(private readonly DependencyProjectionInterface $projection) {}

    public function __invoke(DependencyProjectionRefreshMessage $message): void
    {
        $token = $this->projection->markDirty($message->sourceType, $message->sourceId);
        $source = $this->loadSource($message->sourceType, $message->sourceId);
        if ($source === null) {
            $this->projection->remove($message->sourceType, $message->sourceId, $token);

            return;
        }

        if (!$this->projection->refresh($source, $token)) {
            throw new \RuntimeException(sprintf('Dependency projection refresh for %s:%d lost its revision fence.', $message->sourceType, $message->sourceId));
        }
    }

    protected function loadSource(string $sourceType, int $sourceId): ?AbstractElement
    {
        return ElementService::getElementById($sourceType, $sourceId, ['force' => true]);
    }
}
