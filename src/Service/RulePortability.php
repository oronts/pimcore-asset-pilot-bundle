<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleSetDiff;

/**
 * Exports the configured rule set to a portable artifact and diffs an imported artifact against
 * the rules currently loaded. Rules stay config-only (Decision #11): this never writes rules, it
 * produces a paste-ready config block and a diff so a rule set can be moved between environments.
 */
class RulePortability
{
    public const int FORMAT_VERSION = 1;

    public function __construct(
        protected readonly RuleEngineInterface $ruleEngine,
    ) {}

    /**
     * @return array{format_version: int, rules: array<string, array<string, mixed>>}
     */
    public function export(): array
    {
        return [
            'format_version' => self::FORMAT_VERSION,
            'rules' => $this->currentRules(),
        ];
    }

    /**
     * @param array{rules?: array<string, mixed>} $artifact
     */
    public function diff(array $artifact): RuleSetDiff
    {
        $current = $this->currentRules();

        $imported = [];
        foreach ($artifact['rules'] ?? [] as $name => $config) {
            $imported[(string) $name] = $this->canonicalize((string) $name, is_array($config) ? $config : []);
        }

        $added = [];
        $changed = [];
        $unchanged = [];
        foreach ($imported as $name => $config) {
            if (!array_key_exists($name, $current)) {
                $added[$name] = $config;
            } elseif ($current[$name] === $config) {
                $unchanged[] = $name;
            } else {
                $changed[$name] = ['current' => $current[$name], 'imported' => $config];
            }
        }

        $removed = [];
        foreach ($current as $name => $config) {
            if (!array_key_exists($name, $imported)) {
                $removed[$name] = $config;
            }
        }

        return new RuleSetDiff($added, $removed, $changed, $unchanged);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function currentRules(): array
    {
        $rules = [];
        foreach ($this->ruleEngine->getRules() as $rule) {
            $rules[$rule->name] = $rule->toConfigArray();
        }

        return $rules;
    }

    /**
     * Normalize an imported rule to the canonical config shape (defaults filled) so a diff does not
     * flag default-omitted keys as changes. A structurally invalid rule cannot be canonicalized; it
     * is returned raw so the diff still surfaces it rather than dropping it or throwing.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function canonicalize(string $name, array $config): array
    {
        if (!isset($config['class'], $config['target_path'])) {
            return $config;
        }

        try {
            return Rule::fromConfig($name, $config)->toConfigArray();
        } catch (\Throwable) {
            return $config;
        }
    }
}
