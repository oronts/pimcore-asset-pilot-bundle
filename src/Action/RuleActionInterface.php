<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Action;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

/**
 * A post-move action a rule can run on an organized asset (set a property, assign a tag, derive
 * metadata from the owning object, ...). Tag an implementation with `oronts_asset_pilot.rule_action`;
 * the action is selected by its getType(), matched against each entry in the rule's `actions` config.
 */
interface RuleActionInterface
{
    /** The `type` key this action handles in a rule's `actions` config. */
    public function getType(): string;

    /**
     * @param array<string, mixed> $config the single action's config (includes its `type` plus its own keys)
     */
    public function apply(Asset $asset, AbstractObject $object, array $config): void;
}
