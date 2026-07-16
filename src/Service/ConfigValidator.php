<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Action\RuleActionConfigValidatorInterface;
use Oronts\AssetPilotBundle\Action\RuleActionResolver;
use Oronts\AssetPilotBundle\Condition\ExpressionConditionEvaluator;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\ValidationResult;
use Oronts\AssetPilotBundle\PathResolver\TemplatePathResolver;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data\Fieldcollections;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\DataObject\ClassDefinition\Data\Objectbricks;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Objectbrick;
use Pimcore\Tool;
use Psr\Container\ContainerInterface;

class ConfigValidator
{
    private const array VALID_FILTER_TYPES = ['image', 'video', 'document', 'audio', 'text', 'archive', 'folder', 'unknown'];

    /**
     * @param ContainerInterface $callbacks service locator of services tagged
     *                                      `oronts_asset_pilot.callback`, matching CallbackStrategy
     */
    public function __construct(
        private readonly ContainerInterface $callbacks,
        private readonly ExpressionConditionEvaluator $conditionEvaluator,
        private readonly TemplatePathResolver $pathResolver,
        private readonly ?RuleActionResolver $actionResolver = null,
    ) {}

    /** @return ValidationResult[] */
    public function validate(array $rules): array
    {
        $results = [];

        foreach ($rules as $rule) {
            $results = [...$results, ...$this->validateRule($rule)];
        }

        $results = [...$results, ...$this->validateUniqueNames($rules), ...$this->validateDuplicatePriorities($rules)];

        return $results;
    }

    /** @return ValidationResult[] */
    private function validateRule(Rule $rule): array
    {
        return [
            ...$this->validateClassName($rule),
            ...$this->validateFields($rule),
            ...$this->validateConditionSyntax($rule),
            ...$this->validatePathTemplate($rule),
            ...$this->validateCallbackService($rule),
            ...$this->validateFilterValues($rule),
            ...$this->validateStrategyCallback($rule),
            ...$this->validateLocales($rule),
            ...$this->validateActions($rule),
        ];
    }

    /** @return ValidationResult[] */
    private function validateClassName(Rule $rule): array
    {
        if ($rule->class === '*') {
            return [new ValidationResult($rule->name, 'class_exists', 'pass', 'Wildcard class (*) matches all objects')];
        }

        $classDef = ClassDefinition::getByName($rule->class);
        if ($classDef === null) {
            return [new ValidationResult($rule->name, 'class_exists', 'fail', "Class \"{$rule->class}\" not found in Pimcore")];
        }

        return [new ValidationResult($rule->name, 'class_exists', 'pass', "Class \"{$rule->class}\" exists")];
    }

    /** @return ValidationResult[] */
    private function validateFields(Rule $rule): array
    {
        if ($rule->fields === []) {
            return [new ValidationResult($rule->name, 'fields_exist', 'pass', 'No field restrictions (all fields)')];
        }

        if ($rule->class === '*') {
            return [new ValidationResult($rule->name, 'fields_exist', 'warning', 'Cannot validate fields for wildcard class')];
        }

        $classDef = ClassDefinition::getByName($rule->class);
        if ($classDef === null) {
            return [new ValidationResult($rule->name, 'fields_exist', 'warning', 'Cannot validate fields — class not found')];
        }

        $results = [];
        $fieldDefs = $classDef->getFieldDefinitions();
        $fieldNames = array_keys($fieldDefs);

        // Also collect localized field names
        $localizedFieldNames = [];
        $localizedFields = $classDef->getFieldDefinition('localizedfields');
        if ($localizedFields instanceof Localizedfields) {
            $localizedFieldNames = array_keys($localizedFields->getFieldDefinitions());
        }

        // Qualified names of fields nested in object bricks / field collections, matching what the
        // extractor reports (e.g. "myBrick.image"), so a rule can constrain on a nested field.
        $nestedFieldNames = $this->nestedFieldNames($classDef);

        foreach ($rule->fields as $field) {
            if (in_array($field, $fieldNames, true)) {
                $results[] = new ValidationResult($rule->name, 'fields_exist', 'pass', "Field \"{$field}\" exists in {$rule->class}");
            } elseif (in_array($field, $localizedFieldNames, true)) {
                $results[] = new ValidationResult($rule->name, 'fields_exist', 'pass', "Field \"{$field}\" exists in {$rule->class} (localized)");
            } elseif (in_array($field, $nestedFieldNames, true)) {
                $results[] = new ValidationResult($rule->name, 'fields_exist', 'pass', "Field \"{$field}\" exists in {$rule->class} (nested)");
            } else {
                $results[] = new ValidationResult($rule->name, 'fields_exist', 'fail', "Field \"{$field}\" not found in {$rule->class}");
            }
        }

        return $results;
    }

    /**
     * Qualified "container.field" names for every field nested in this class's object bricks and
     * field collections (descending one level into a nested localized container), mirroring the
     * names AssetFieldExtractor reports so a rule's `fields` constraint can target them.
     *
     * @return string[]
     */
    private function nestedFieldNames(ClassDefinition $classDef): array
    {
        $names = [];

        foreach ($classDef->getFieldDefinitions() as $fieldDef) {
            $nestedDefs = match (true) {
                $fieldDef instanceof Objectbricks => $this->allowedDefinitions($fieldDef->getAllowedTypes(), Objectbrick\Definition::class),
                $fieldDef instanceof Fieldcollections => $this->allowedDefinitions($fieldDef->getAllowedTypes(), Fieldcollection\Definition::class),
                default => [],
            };

            foreach ($nestedDefs as $nestedDef) {
                foreach ($nestedDef->getFieldDefinitions() as $sub) {
                    if ($sub instanceof Localizedfields) {
                        foreach (array_keys($sub->getFieldDefinitions()) as $inner) {
                            $names[] = $fieldDef->getName() . '.' . $inner;
                        }

                        continue;
                    }

                    $names[] = $fieldDef->getName() . '.' . $sub->getName();
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param string[]                                           $types
     * @param class-string<Objectbrick\Definition|Fieldcollection\Definition> $definitionClass
     * @return array<Objectbrick\Definition|Fieldcollection\Definition>
     */
    private function allowedDefinitions(array $types, string $definitionClass): array
    {
        $defs = [];
        foreach ($types as $type) {
            $def = $definitionClass::getByKey($type);
            if ($def !== null) {
                $defs[] = $def;
            }
        }

        return $defs;
    }

    /** @return ValidationResult[] */
    private function validateConditionSyntax(Rule $rule): array
    {
        if ($rule->condition === null || $rule->condition === '') {
            return [new ValidationResult($rule->name, 'condition_syntax', 'pass', 'No condition defined')];
        }

        try {
            $this->conditionEvaluator->validateSyntax($rule->condition);

            return [new ValidationResult($rule->name, 'condition_syntax', 'pass', "Condition syntax valid: {$rule->condition}")];
        } catch (\Throwable $e) {
            return [new ValidationResult($rule->name, 'condition_syntax', 'fail', "Condition syntax error: {$e->getMessage()}")];
        }
    }

    /** @return ValidationResult[] */
    private function validatePathTemplate(Rule $rule): array
    {
        try {
            $this->pathResolver->validateTemplate($rule->targetPath);

            return [new ValidationResult($rule->name, 'path_template', 'pass', "Path template syntax valid: {$rule->targetPath}")];
        } catch (\Throwable $e) {
            return [new ValidationResult($rule->name, 'path_template', 'fail', "Path template syntax error: {$e->getMessage()}")];
        }
    }

    /** @return ValidationResult[] */
    private function validateCallbackService(Rule $rule): array
    {
        if ($rule->strategy->value !== 'callback') {
            return [];
        }

        if ($rule->callback === null || $rule->callback === '') {
            return [new ValidationResult($rule->name, 'callback_service', 'fail', 'Callback strategy requires a callback service ID')];
        }

        if ($this->callbacks->has($rule->callback)) {
            return [new ValidationResult($rule->name, 'callback_service', 'pass', "Callback service \"{$rule->callback}\" exists")];
        }

        return [new ValidationResult($rule->name, 'callback_service', 'fail', "Callback service \"{$rule->callback}\" not found. Tag it with \"oronts_asset_pilot.callback\".")];
    }

    /** @return ValidationResult[] */
    private function validateFilterValues(Rule $rule): array
    {
        $results = [];
        $filters = $rule->filters;

        $types = $filters['types'] ?? [];
        if ($types !== []) {
            foreach ($types as $type) {
                if (!in_array($type, self::VALID_FILTER_TYPES, true)) {
                    $results[] = new ValidationResult($rule->name, 'filter_types', 'warning', "Unknown filter type \"{$type}\". Valid: " . implode(', ', self::VALID_FILTER_TYPES));
                }
            }
            if ($results === []) {
                $results[] = new ValidationResult($rule->name, 'filter_types', 'pass', 'Filter types are valid');
            }
        }

        $extensions = $filters['extensions'] ?? [];
        if ($extensions !== []) {
            foreach ($extensions as $ext) {
                if (!preg_match('/^[a-z0-9]+$/', $ext)) {
                    $results[] = new ValidationResult($rule->name, 'filter_extensions', 'warning', "Extension \"{$ext}\" should be lowercase alphanumeric");
                }
            }
            if (!array_filter($results, static fn (ValidationResult $r) => $r->check === 'filter_extensions')) {
                $results[] = new ValidationResult($rule->name, 'filter_extensions', 'pass', 'Filter extensions are valid');
            }
        }

        $minSize = $filters['min_size'] ?? null;
        $maxSize = $filters['max_size'] ?? null;
        if ($minSize !== null && $minSize < 0) {
            $results[] = new ValidationResult($rule->name, 'filter_size', 'fail', 'filters.min_size cannot be negative');
        }
        if ($maxSize !== null && $maxSize < 0) {
            $results[] = new ValidationResult($rule->name, 'filter_size', 'fail', 'filters.max_size cannot be negative');
        }
        if ($minSize !== null && $maxSize !== null && $minSize > $maxSize) {
            $results[] = new ValidationResult($rule->name, 'filter_size', 'fail', "filters.min_size ({$minSize}) is greater than filters.max_size ({$maxSize})");
        }

        return $results;
    }

    /** @return ValidationResult[] */
    private function validateStrategyCallback(Rule $rule): array
    {
        if ($rule->strategy->value !== 'callback' && $rule->callback !== null && $rule->callback !== '') {
            return [new ValidationResult($rule->name, 'strategy_callback', 'warning', "Callback \"{$rule->callback}\" is set but strategy is \"{$rule->strategy->value}\" (not \"callback\")")];
        }

        return [];
    }

    /** @return ValidationResult[] */
    private function validateLocales(Rule $rule): array
    {
        if ($rule->locales === []) {
            return [];
        }
        $validLocales = $this->validLocales();
        $unknown = array_values(array_diff($rule->locales, $validLocales));
        if ($unknown !== []) {
            return [new ValidationResult($rule->name, 'locales', 'fail', 'Unknown Pimcore locale(s): ' . implode(', ', $unknown))];
        }

        return [new ValidationResult($rule->name, 'locales', 'pass', 'Rule locales are valid')];
    }

    /** @return list<string> */
    protected function validLocales(): array
    {
        return Tool::getValidLanguages();
    }

    /** @return ValidationResult[] */
    private function validateActions(Rule $rule): array
    {
        if ($rule->actions === []) {
            return [];
        }
        if ($this->actionResolver === null) {
            return [new ValidationResult($rule->name, 'actions', 'warning', 'Action services are unavailable to semantic validation')];
        }

        $results = [];
        foreach ($rule->actions as $index => $config) {
            if (!is_array($config)) {
                $results[] = new ValidationResult($rule->name, 'actions', 'fail', sprintf('Action %d must be a key/value map', $index + 1));
                continue;
            }
            $type = trim((string) ($config['type'] ?? ''));
            $action = $type === '' ? null : $this->actionResolver->resolve($type);
            if ($action === null) {
                $results[] = new ValidationResult($rule->name, 'actions', 'fail', sprintf('Action %d has unknown type "%s"', $index + 1, $type));
                continue;
            }
            if (!$action instanceof RuleActionConfigValidatorInterface) {
                $results[] = new ValidationResult($rule->name, 'actions', 'pass', sprintf('Action %d type "%s" is registered', $index + 1, $type));
                continue;
            }
            $errors = $action->validateConfig($config);
            $results[] = new ValidationResult(
                $rule->name,
                'actions',
                $errors === [] ? 'pass' : 'fail',
                $errors === []
                    ? sprintf('Action %d type "%s" is valid', $index + 1, $type)
                    : sprintf('Action %d type "%s" %s', $index + 1, $type, implode('; ', $errors)),
            );
        }

        return $results;
    }

    /** @param list<Rule> $rules
     * @return ValidationResult[]
     */
    private function validateUniqueNames(array $rules): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($rules as $rule) {
            if (isset($seen[$rule->name])) {
                $duplicates[$rule->name] = true;
            }
            $seen[$rule->name] = true;
        }

        return array_map(
            static fn (string $name): ValidationResult => new ValidationResult($name, 'unique_name', 'fail', sprintf('Duplicate rule name "%s"', $name)),
            array_keys($duplicates),
        );
    }

    /** @return ValidationResult[] */
    private function validateDuplicatePriorities(array $rules): array
    {
        $results = [];
        $byClass = [];

        foreach ($rules as $rule) {
            $key = $rule->class . ':' . $rule->priority;
            $byClass[$key][] = $rule->name;
        }

        foreach ($byClass as $key => $ruleNames) {
            if (count($ruleNames) > 1) {
                [$class, $priority] = explode(':', $key, 2);
                $results[] = new ValidationResult(
                    implode(', ', $ruleNames),
                    'duplicate_priority',
                    'warning',
                    'Rules ' . implode(', ', $ruleNames) . " target class \"{$class}\" with same priority {$priority}",
                );
            }
        }

        return $results;
    }
}
