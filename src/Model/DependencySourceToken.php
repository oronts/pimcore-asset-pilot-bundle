<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

readonly class DependencySourceToken
{
    public function __construct(
        public string $sourceKey,
        public int $revision,
    ) {
        if ($sourceKey === '' || $revision <= 0) {
            throw new \InvalidArgumentException('A dependency source token requires a key and positive revision.');
        }
    }
}
