<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Message;

readonly class DependencyProjectionRefreshMessage
{
    public function __construct(
        public string $sourceType,
        public int $sourceId,
    ) {
        if (!in_array($sourceType, ['object', 'document', 'asset'], true) || $sourceId <= 0) {
            throw new \InvalidArgumentException('A dependency refresh message requires a supported source type and positive ID.');
        }
    }
}
