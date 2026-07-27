<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\Rule;

/**
 * A deterministic fingerprint of the rule values that affect how a move executes (name + canonical
 * config), so the reviewed-apply gate can reject a live rule that resolves to the reviewed target but
 * would run different strategy/action/callback semantics. Non-final for extension.
 */
class RuleExecutionFingerprint
{
    public function forRule(Rule $rule): string
    {
        $config = $rule->toConfigArray();
        // Actions execute in insertion order, so keep them a list; canonicalize() must not ksort them.
        $config['actions'] = array_values($config['actions']);

        return hash('sha256', $this->encode([
            'name' => $rule->name,
            'config' => $this->canonicalize($config),
        ]));
    }

    /**
     * Recursively sort associative keys (preserving list order) and normalise floats for a hash that is
     * stable across SAPIs.
     */
    protected function canonicalize(mixed $value): mixed
    {
        if (is_float($value)) {
            return sprintf('%.17G', $value);
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalize(...), $value);
    }

    protected function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
