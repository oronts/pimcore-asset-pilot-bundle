<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Dto\Request;

readonly class OrganizeRequest
{
    public function __construct(
        public int $objectId,
        public bool $dryRun = false,
        public bool $async = false,
    ) {}
}
