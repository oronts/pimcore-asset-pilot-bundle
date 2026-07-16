<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

final readonly class ApplyPlanTarget
{
    public function __construct(
        public string $id,
        public string $fingerprint,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('The apply plan target ID must not be empty.');
        }
        if ($fingerprint === '') {
            throw new \InvalidArgumentException('The apply plan target fingerprint must not be empty.');
        }
    }
}
