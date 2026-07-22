<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use Oronts\AssetPilotBundle\Zip\ZipDownloadPlan;

interface ZipDownloadTokenStoreInterface
{
    /** @param list<int> $assetIds */
    public function issue(array $assetIds, ZipBuildOptions $options, ActorContext $actor): string;

    public function claim(string $token, ActorContext $actor): ?ZipDownloadPlan;
}
