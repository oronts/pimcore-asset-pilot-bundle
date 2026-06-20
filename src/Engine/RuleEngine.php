<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Engine;

use Oronts\AssetPilotBundle\Condition\ConditionEvaluatorInterface;
use Oronts\AssetPilotBundle\Filter\AssetFilterInterface;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleEvaluation;
use Oronts\AssetPilotBundle\Model\RuleMatch;
use Oronts\AssetPilotBundle\PathResolver\PathResolverInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;

class RuleEngine implements RuleEngineInterface
{
    /** @var Rule[] */
    protected readonly array $sortedRules;

    /**
     * @param iterable<Rule|array<string, mixed>>      $rules         configured rules
     * @param iterable<RuleProviderInterface>          $ruleProviders consumer-tagged rule providers
     */
    public function __construct(
        iterable $rules,
        protected readonly ConditionEvaluatorInterface $conditionEvaluator,
        protected readonly PathResolverInterface $pathResolver,
        protected readonly AssetFilterInterface $filter,
        protected readonly LoggerInterface $logger,
        iterable $ruleProviders = [],
    ) {
        $parsed = [];
        foreach ($rules as $rule) {
            $parsed[] = $this->normalizeRule($rule);
        }
        foreach ($ruleProviders as $provider) {
            foreach ($provider->getRules() as $rule) {
                $parsed[] = $this->normalizeRule($rule);
            }
        }
        $parsed = array_filter($parsed);
        usort($parsed, static fn (Rule $a, Rule $b): int => $b->priority <=> $a->priority);
        $this->sortedRules = $parsed;
    }

    private function normalizeRule(mixed $rule): ?Rule
    {
        if ($rule instanceof Rule) {
            return $rule;
        }
        if (is_array($rule)) {
            return Rule::fromConfig($rule['name'] ?? 'unnamed', $rule);
        }

        return null;
    }

    /** @return RuleMatch[] */
    public function match(AbstractObject $object, Asset $asset): array
    {
        return $this->evaluateRules($object, $asset, null, null);
    }

    /** @return RuleMatch[] */
    public function matchField(AbstractObject $object, Asset $asset, string $fieldName, ?string $locale = null): array
    {
        return $this->evaluateRules($object, $asset, $fieldName, $locale);
    }

    /**
     * Shared matching pass for match() (field-agnostic) and matchField() (field-scoped). A null
     * $fieldName skips the field constraint; everything else (enabled, class, condition, filter,
     * path) is identical, which is why both must run the same gate sequence.
     *
     * @return RuleMatch[]
     */
    private function evaluateRules(AbstractObject $object, Asset $asset, ?string $fieldName, ?string $locale): array
    {
        $matches = [];

        foreach ($this->sortedRules as $rule) {
            if (!$rule->enabled || !$this->matchesClass($rule, $object)) {
                continue;
            }

            if ($fieldName !== null && !$this->matchesFields($rule, $fieldName)) {
                continue;
            }

            if (!$this->matchesLocale($rule, $locale)) {
                continue;
            }

            if (!$this->conditionEvaluator->evaluate($object, $asset, $rule, $locale)) {
                continue;
            }

            if (!$this->filter->accept($asset, $object, $rule)) {
                continue;
            }

            $resolvedPath = $this->pathResolver->resolve($object, $asset, $rule, $locale);

            $this->logger->debug('Rule "{rule}" matched object {objectId} asset {assetId} (field: {field}) -> {path}', [
                'rule' => $rule->name,
                'objectId' => $object->getId(),
                'assetId' => $asset->getId(),
                'field' => $fieldName ?? 'any',
                'path' => $resolvedPath,
            ]);

            $matches[] = new RuleMatch(
                rule: $rule,
                object: $object,
                asset: $asset,
                resolvedPath: $resolvedPath,
                locale: $locale,
            );
        }

        return $matches;
    }

    /**
     * @return array{matches: RuleMatch[], evaluations: RuleEvaluation[]}
     */
    public function explain(AbstractObject $object, Asset $asset, ?string $fieldName = null, ?string $locale = null): array
    {
        $matches = [];
        $evaluations = [];

        foreach ($this->sortedRules as $rule) {
            if (!$rule->enabled) {
                $evaluations[] = $this->rejected($rule, 'disabled', enabled: false);
                continue;
            }

            if (!$this->matchesClass($rule, $object)) {
                $evaluations[] = $this->rejected($rule, 'class_mismatch', filterDetails: 'expected ' . $rule->class . ', got ' . ($this->objectClassName($object) ?? 'Folder'));
                continue;
            }

            if ($fieldName !== null && !$this->matchesFields($rule, $fieldName)) {
                $evaluations[] = $this->rejected($rule, 'field_mismatch', filterDetails: 'field "' . $fieldName . '" not in [' . implode(', ', $rule->fields) . ']');
                continue;
            }

            if (!$this->matchesLocale($rule, $locale)) {
                $evaluations[] = $this->rejected($rule, 'locale_mismatch', filterDetails: 'locale "' . ($locale ?? 'none') . '" not in [' . implode(', ', $rule->locales) . ']');
                continue;
            }

            $conditionResult = null;
            $conditionError = null;
            if ($rule->condition !== null && $rule->condition !== '') {
                try {
                    $conditionResult = $this->conditionEvaluator->evaluateStrict($object, $asset, $rule, $locale);
                } catch (\Throwable $e) {
                    $conditionResult = false;
                    $conditionError = $e->getMessage();
                }
            } else {
                $conditionResult = true;
            }

            if (!$conditionResult) {
                $evaluations[] = $this->rejected($rule, 'condition_failed', conditionResult: false, conditionError: $conditionError);
                continue;
            }

            if (!$this->filter->accept($asset, $object, $rule)) {
                $evaluations[] = $this->rejected($rule, 'filter_rejected', filterDetails: 'asset rejected by filter', conditionResult: true);
                continue;
            }

            $resolvedPath = $this->pathResolver->resolve($object, $asset, $rule, $locale);

            $evaluations[] = new RuleEvaluation(
                ruleName: $rule->name,
                matched: true,
                rejectionReason: null,
                conditionExpression: $rule->condition,
                conditionResult: true,
                conditionError: null,
                filterDetails: null,
                resolvedPath: $resolvedPath,
                priority: $rule->priority,
                enabled: true,
            );

            $matches[] = new RuleMatch(
                rule: $rule,
                object: $object,
                asset: $asset,
                resolvedPath: $resolvedPath,
                locale: $locale,
            );
        }

        return ['matches' => $matches, 'evaluations' => $evaluations];
    }

    protected function rejected(
        Rule $rule,
        string $reason,
        ?string $filterDetails = null,
        ?bool $conditionResult = null,
        ?string $conditionError = null,
        bool $enabled = true,
    ): RuleEvaluation {
        return new RuleEvaluation(
            ruleName: $rule->name,
            matched: false,
            rejectionReason: $reason,
            conditionExpression: $rule->condition,
            conditionResult: $conditionResult,
            conditionError: $conditionError,
            filterDetails: $filterDetails,
            resolvedPath: null,
            priority: $rule->priority,
            enabled: $enabled,
        );
    }

    /** @return Rule[] */
    public function getRules(): array
    {
        return $this->sortedRules;
    }

    /** @return Rule[] */
    public function findRulesForClass(string $className): array
    {
        return array_values(array_filter(
            $this->sortedRules,
            static fn (Rule $rule): bool => $rule->enabled && ($rule->class === '*' || $rule->class === $className),
        ));
    }

    protected function matchesClass(Rule $rule, AbstractObject $object): bool
    {
        if ($rule->class === '*') {
            return true;
        }

        return $this->objectClassName($object) === $rule->class;
    }

    private function objectClassName(AbstractObject $object): ?string
    {
        return $object instanceof Concrete ? $object->getClassName() : null;
    }

    protected function matchesFields(Rule $rule, string $fieldName): bool
    {
        if ($rule->fields === []) {
            return true;
        }

        return in_array($fieldName, $rule->fields, true);
    }

    /**
     * A rule with no `locales` matches any locale (and non-localized fields). A locale-scoped rule
     * matches only its listed locales, so it never touches a non-localized field (locale null).
     */
    protected function matchesLocale(Rule $rule, ?string $locale): bool
    {
        if ($rule->locales === []) {
            return true;
        }

        return $locale !== null && in_array($locale, $rule->locales, true);
    }
}
