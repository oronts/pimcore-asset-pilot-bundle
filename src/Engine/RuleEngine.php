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
use Psr\Log\LoggerInterface;

class RuleEngine
{
    /** @var Rule[] */
    protected readonly array $sortedRules;

    public function __construct(
        array $rules,
        protected readonly ConditionEvaluatorInterface $conditionEvaluator,
        protected readonly PathResolverInterface $pathResolver,
        protected readonly AssetFilterInterface $filter,
        protected readonly LoggerInterface $logger,
    ) {
        $parsed = [];
        foreach ($rules as $rule) {
            if ($rule instanceof Rule) {
                $parsed[] = $rule;
            } elseif (is_array($rule)) {
                $name = $rule['name'] ?? 'unnamed';
                $parsed[] = Rule::fromConfig($name, $rule);
            }
        }
        usort($parsed, static fn (Rule $a, Rule $b): int => $b->priority <=> $a->priority);
        $this->sortedRules = $parsed;
    }

    /** @return RuleMatch[] */
    public function match(AbstractObject $object, Asset $asset): array
    {
        $matches = [];

        foreach ($this->sortedRules as $rule) {
            if (!$rule->enabled) {
                $this->logger->debug('Rule "{rule}" is disabled, skipping.', ['rule' => $rule->name]);
                continue;
            }

            if (!$this->matchesClass($rule, $object)) {
                $this->logger->debug('Rule "{rule}" class mismatch for object {id} (expected "{expected}", got "{actual}").', [
                    'rule' => $rule->name,
                    'id' => $object->getId(),
                    'expected' => $rule->class,
                    'actual' => $object->getClassName(),
                ]);
                continue;
            }

            if (!$this->conditionEvaluator->evaluate($object, $asset, $rule)) {
                $this->logger->debug('Rule "{rule}" condition not met for object {objectId} and asset {assetId}.', [
                    'rule' => $rule->name,
                    'objectId' => $object->getId(),
                    'assetId' => $asset->getId(),
                ]);
                continue;
            }

            if (!$this->filter->accept($asset, $object, $rule)) {
                $this->logger->debug('Rule "{rule}" filter rejected asset {assetId}.', [
                    'rule' => $rule->name,
                    'assetId' => $asset->getId(),
                ]);
                continue;
            }

            $resolvedPath = $this->pathResolver->resolve($object, $asset, $rule);

            $this->logger->debug('Rule "{rule}" matched object {objectId} and asset {assetId}, resolved path: {path}.', [
                'rule' => $rule->name,
                'objectId' => $object->getId(),
                'assetId' => $asset->getId(),
                'path' => $resolvedPath,
            ]);

            $matches[] = new RuleMatch(
                rule: $rule,
                object: $object,
                asset: $asset,
                resolvedPath: $resolvedPath,
            );
        }

        return $matches;
    }

    /**
     * @return RuleMatch[]
     */
    public function matchField(AbstractObject $object, Asset $asset, string $fieldName, ?string $locale = null): array
    {
        $matches = [];

        foreach ($this->sortedRules as $rule) {
            if (!$rule->enabled) {
                continue;
            }

            if (!$this->matchesClass($rule, $object)) {
                continue;
            }

            if (!$this->matchesFields($rule, $fieldName)) {
                $this->logger->debug('Rule "{rule}" does not target field "{field}".', [
                    'rule' => $rule->name,
                    'field' => $fieldName,
                ]);
                continue;
            }

            if (!$this->conditionEvaluator->evaluate($object, $asset, $rule)) {
                continue;
            }

            if (!$this->filter->accept($asset, $object, $rule)) {
                continue;
            }

            $resolvedPath = $this->pathResolver->resolve($object, $asset, $rule, $locale);

            $this->logger->debug('Rule "{rule}" matched field "{field}" for object {objectId} and asset {assetId} (locale: {locale}).', [
                'rule' => $rule->name,
                'field' => $fieldName,
                'objectId' => $object->getId(),
                'assetId' => $asset->getId(),
                'locale' => $locale ?? 'none',
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
                $evaluations[] = new RuleEvaluation(
                    ruleName: $rule->name,
                    matched: false,
                    rejectionReason: 'disabled',
                    conditionExpression: $rule->condition,
                    conditionResult: null,
                    conditionError: null,
                    filterDetails: null,
                    resolvedPath: null,
                    priority: $rule->priority,
                    enabled: false,
                );
                continue;
            }

            if (!$this->matchesClass($rule, $object)) {
                $evaluations[] = new RuleEvaluation(
                    ruleName: $rule->name,
                    matched: false,
                    rejectionReason: 'class_mismatch',
                    conditionExpression: $rule->condition,
                    conditionResult: null,
                    conditionError: null,
                    filterDetails: 'expected ' . $rule->class . ', got ' . $object->getClassName(),
                    resolvedPath: null,
                    priority: $rule->priority,
                    enabled: true,
                );
                continue;
            }

            if ($fieldName !== null && !$this->matchesFields($rule, $fieldName)) {
                $evaluations[] = new RuleEvaluation(
                    ruleName: $rule->name,
                    matched: false,
                    rejectionReason: 'field_mismatch',
                    conditionExpression: $rule->condition,
                    conditionResult: null,
                    conditionError: null,
                    filterDetails: 'field "' . $fieldName . '" not in [' . implode(', ', $rule->fields) . ']',
                    resolvedPath: null,
                    priority: $rule->priority,
                    enabled: true,
                );
                continue;
            }

            $conditionResult = null;
            $conditionError = null;
            if ($rule->condition !== null && $rule->condition !== '') {
                try {
                    $conditionResult = $this->conditionEvaluator->evaluate($object, $asset, $rule);
                } catch (\Throwable $e) {
                    $conditionResult = false;
                    $conditionError = $e->getMessage();
                }
            } else {
                $conditionResult = true;
            }

            if (!$conditionResult) {
                $evaluations[] = new RuleEvaluation(
                    ruleName: $rule->name,
                    matched: false,
                    rejectionReason: 'condition_failed',
                    conditionExpression: $rule->condition,
                    conditionResult: false,
                    conditionError: $conditionError,
                    filterDetails: null,
                    resolvedPath: null,
                    priority: $rule->priority,
                    enabled: true,
                );
                continue;
            }

            if (!$this->filter->accept($asset, $object, $rule)) {
                $evaluations[] = new RuleEvaluation(
                    ruleName: $rule->name,
                    matched: false,
                    rejectionReason: 'filter_rejected',
                    conditionExpression: $rule->condition,
                    conditionResult: true,
                    conditionError: null,
                    filterDetails: 'asset rejected by filter',
                    resolvedPath: null,
                    priority: $rule->priority,
                    enabled: true,
                );
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

        return $object->getClassName() === $rule->class;
    }

    protected function matchesFields(Rule $rule, string $fieldName): bool
    {
        if ($rule->fields === []) {
            return true;
        }

        return in_array($fieldName, $rule->fields, true);
    }
}
