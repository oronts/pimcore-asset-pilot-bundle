<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use Oronts\AssetPilotBundle\Zip\ZipBuildResult;

interface AssetZipServiceInterface
{
    /** @param list<int> $assetIds */
    public function buildFromAssetIds(array $assetIds, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult;

    public function buildFromFolder(int $folderId, bool $recursive = true, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult;

    /** @param list<int> $objectIds */
    public function buildFromObjects(array $objectIds, ?ZipBuildOptions $options = null, ?ActorContext $actor = null): ZipBuildResult;
}
