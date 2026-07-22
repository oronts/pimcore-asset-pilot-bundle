<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Strategy;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

interface ConflictStrategyInterface
{
    /**
     * Return a decision without mutating Pimcore or external state. The method is also called while
     * building signed previews; $dryRun lets integrations avoid live-only reads or diagnostics.
     */
    public function resolve(Asset $asset, AbstractObject $object, Rule $rule, bool $dryRun): bool;

    public function supports(MoveStrategy $strategy): bool;
}
