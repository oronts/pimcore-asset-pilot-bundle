<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Exception;

/**
 * Thrown when a deleter refreshes a deletion fence it no longer owns: the row was reaped after its lease
 * expired (the owner had stalled) or replaced by another operation. The deleter must abort rather than
 * proceed, because a stale owner deleting an asset another operation now guards would break the fence.
 */
class AssetDeletionFenceLostException extends \RuntimeException
{
    public function __construct(public readonly int $assetId)
    {
        parent::__construct(sprintf('The deletion fence for asset %d was lost before delete; aborting.', $assetId));
    }
}
