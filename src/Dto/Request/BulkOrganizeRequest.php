<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Dto\Request;

readonly class BulkOrganizeRequest
{
    public function __construct(
        public string $className,
        public array $objectIds = [],
        public bool $dryRun = false,
        public bool $async = true,
    ) {}
}
