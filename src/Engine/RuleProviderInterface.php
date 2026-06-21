<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Engine;

use Oronts\AssetPilotBundle\Model\Rule;

/**
 * Lets a consumer contribute rules programmatically instead of only through YAML config. Tag a
 * service with `oronts_asset_pilot.rule_provider` (auto-tagged when it implements this interface);
 * the RuleEngine merges its rules with the configured ones and re-sorts by priority.
 */
interface RuleProviderInterface
{
    /** @return iterable<Rule|array<string, mixed>> */
    public function getRules(): iterable;
}
