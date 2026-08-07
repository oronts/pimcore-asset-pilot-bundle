<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

readonly class AutomaticOrganizeIntentBinding
{
    public function __construct(
        public string $runId,
        public bool $isNew,
    ) {}
}
