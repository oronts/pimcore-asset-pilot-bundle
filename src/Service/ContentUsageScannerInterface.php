<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Pimcore\Model\Asset;
use Symfony\Contracts\Service\ResetInterface;

interface ContentUsageScannerInterface extends ResetInterface
{
    public function canVerify(): bool;

    public function isReferencedInContent(Asset $asset): bool;

    /**
     * Fresh, uncached content scan for a fenced safety re-read: it must not reuse a negative result cached
     * before the caller acquired its deletion fence, or a content reference committed in that window would
     * be missed and the asset wrongly deleted.
     */
    public function freshlyReferencedInContent(Asset $asset): bool;
}
