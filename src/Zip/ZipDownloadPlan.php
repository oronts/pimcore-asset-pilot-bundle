<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Zip;

readonly class ZipDownloadPlan
{
    /** @param list<int> $assetIds */
    public function __construct(
        public array $assetIds,
        public ZipBuildOptions $options,
    ) {}
}
