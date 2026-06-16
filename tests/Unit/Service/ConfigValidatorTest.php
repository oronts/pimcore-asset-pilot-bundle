<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Condition\ExpressionConditionEvaluator;
use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\ValidationResult;
use Oronts\AssetPilotBundle\PathResolver\TemplatePathResolver;
use Oronts\AssetPilotBundle\Service\ConfigValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests ConfigValidator in pure unit-test mode.
 * Tests that call ClassDefinition::getByName() (class_exists, fields_exist) are limited
 * to wildcard rules since static Pimcore calls require a booted kernel.
 */
#[CoversClass(ConfigValidator::class)]
class ConfigValidatorTest extends TestCase
{
    private ContainerInterface $container;
    private ConfigValidator $validator;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->validator = new ConfigValidator(
            $this->container,
            new NullLogger(),
            new ExpressionConditionEvaluator(new NullLogger()),
            new TemplatePathResolver(new NullLogger()),
        );
    }

    private function createRule(
        string $name = 'test_rule',
        string $class = '*',
        array $fields = [],
        ?string $condition = null,
        string $targetPath = '/Products/{{ object.getKey() }}',
        MoveStrategy $strategy = MoveStrategy::Always,
        ?string $callback = null,
        int $priority = 10,
        bool $enabled = true,
        array $filters = [],
    ): Rule {
        return new Rule(
            name: $name,
            class: $class,
            fields: $fields,
            condition: $condition,
            targetPath: $targetPath,
            strategy: $strategy,
            callback: $callback,
            priority: $priority,
            enabled: $enabled,
            filters: $filters,
        );
    }

    #[Test]
    public function validateWildcardClassPasses(): void
    {
        $rule = $this->createRule(class: '*');
        $results = $this->validator->validate([$rule]);

        $classResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'class_exists');
        $classResult = array_values($classResults)[0];

        self::assertSame('pass', $classResult->status);
        self::assertStringContainsString('Wildcard', $classResult->message);
    }

    #[Test]
    public function validateValidConditionPasses(): void
    {
        $rule = $this->createRule(condition: 'object.getId() > 0');
        $results = $this->validator->validate([$rule]);

        $condResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'condition_syntax');
        $condResult = array_values($condResults)[0];

        self::assertSame('pass', $condResult->status);
    }

    #[Test]
    public function validateConditionUsingBundleFunctionsPasses(): void
    {
        $rule = $this->createRule(condition: 'is_image(asset) and asset_type(asset) == "image"');
        $results = $this->validator->validate([$rule]);

        $condResult = array_values(array_filter($results, static fn (ValidationResult $r) => $r->check === 'condition_syntax'))[0];

        self::assertSame('pass', $condResult->status, 'conditions using the bundle\'s own functions must validate');
    }

    #[Test]
    public function validatePathTemplateUsingBundleFiltersPasses(): void
    {
        $rule = $this->createRule(targetPath: '/Products/{{ object.getKey()|safe_key }}/{{ coalesce(object.getSapId(), "unknown") }}');
        $results = $this->validator->validate([$rule]);

        $pathResult = array_values(array_filter($results, static fn (ValidationResult $r) => $r->check === 'path_template'))[0];

        self::assertSame('pass', $pathResult->status, 'templates using the bundle\'s own filters/functions must validate');
    }

    #[Test]
    public function validateInvalidConditionFails(): void
    {
        $rule = $this->createRule(condition: 'object.getId(( > 0');
        $results = $this->validator->validate([$rule]);

        $condResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'condition_syntax');
        $condResult = array_values($condResults)[0];

        self::assertSame('fail', $condResult->status);
    }

    #[Test]
    public function validateNoConditionPasses(): void
    {
        $rule = $this->createRule(condition: null);
        $results = $this->validator->validate([$rule]);

        $condResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'condition_syntax');
        $condResult = array_values($condResults)[0];

        self::assertSame('pass', $condResult->status);
        self::assertStringContainsString('No condition', $condResult->message);
    }

    #[Test]
    public function validateValidPathTemplatePasses(): void
    {
        $rule = $this->createRule(targetPath: '/Products/{{ object.getKey() }}/Images');
        $results = $this->validator->validate([$rule]);

        $pathResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'path_template');
        $pathResult = array_values($pathResults)[0];

        self::assertSame('pass', $pathResult->status);
    }

    #[Test]
    public function validateInvalidPathTemplateFails(): void
    {
        $rule = $this->createRule(targetPath: '/Products/{{ object.getKey() }');
        $results = $this->validator->validate([$rule]);

        $pathResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'path_template');
        $pathResult = array_values($pathResults)[0];

        self::assertSame('fail', $pathResult->status);
    }

    #[Test]
    public function validateCallbackExistsPasses(): void
    {
        $this->container->method('has')->with('app.my_callback')->willReturn(true);

        $rule = $this->createRule(strategy: MoveStrategy::Callback, callback: 'app.my_callback');
        $results = $this->validator->validate([$rule]);

        $cbResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'callback_service');
        $cbResult = array_values($cbResults)[0];

        self::assertSame('pass', $cbResult->status);
    }

    #[Test]
    public function validateCallbackMissingFails(): void
    {
        $this->container->method('has')->willReturn(false);

        $rule = $this->createRule(strategy: MoveStrategy::Callback, callback: 'app.missing');
        $results = $this->validator->validate([$rule]);

        $cbResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'callback_service');
        $cbResult = array_values($cbResults)[0];

        self::assertSame('fail', $cbResult->status);
    }

    #[Test]
    public function validateValidFilterTypesPasses(): void
    {
        $rule = $this->createRule(filters: ['types' => ['image', 'video'], 'extensions' => []]);
        $results = $this->validator->validate([$rule]);

        $typeResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'filter_types');
        self::assertNotEmpty($typeResults);
        $typeResult = array_values($typeResults)[0];
        self::assertSame('pass', $typeResult->status);
    }

    #[Test]
    public function validateInvalidFilterTypeWarns(): void
    {
        $rule = $this->createRule(filters: ['types' => ['image', 'invalid_type'], 'extensions' => []]);
        $results = $this->validator->validate([$rule]);

        $typeResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'filter_types');
        $hasWarning = false;
        foreach ($typeResults as $r) {
            if ($r->status === 'warning') {
                $hasWarning = true;
                self::assertStringContainsString('invalid_type', $r->message);
            }
        }
        self::assertTrue($hasWarning);
    }

    #[Test]
    public function validateValidExtensionsPasses(): void
    {
        $rule = $this->createRule(filters: ['types' => [], 'extensions' => ['jpg', 'png', 'webp']]);
        $results = $this->validator->validate([$rule]);

        $extResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'filter_extensions');
        self::assertNotEmpty($extResults);
        $extResult = array_values($extResults)[0];
        self::assertSame('pass', $extResult->status);
    }

    #[Test]
    public function validateInvalidExtensionWarns(): void
    {
        $rule = $this->createRule(filters: ['types' => [], 'extensions' => ['JPG', 'png with spaces']]);
        $results = $this->validator->validate([$rule]);

        $extResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'filter_extensions');
        $hasWarning = false;
        foreach ($extResults as $r) {
            if ($r->status === 'warning') {
                $hasWarning = true;
            }
        }
        self::assertTrue($hasWarning);
    }

    #[Test]
    public function validateDuplicatePrioritiesWarns(): void
    {
        $r1 = $this->createRule(name: 'rule_a', class: '*', priority: 10);
        $r2 = $this->createRule(name: 'rule_b', class: '*', priority: 10);
        $results = $this->validator->validate([$r1, $r2]);

        $dupResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'duplicate_priority');
        self::assertNotEmpty($dupResults);

        $dupResult = array_values($dupResults)[0];
        self::assertSame('warning', $dupResult->status);
        self::assertStringContainsString('rule_a', $dupResult->message);
        self::assertStringContainsString('rule_b', $dupResult->message);
    }

    #[Test]
    public function validateStrategyCallbackMismatchWarns(): void
    {
        $rule = $this->createRule(strategy: MoveStrategy::Always, callback: 'app.some_service');
        $results = $this->validator->validate([$rule]);

        $scResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'strategy_callback');
        self::assertNotEmpty($scResults);

        $scResult = array_values($scResults)[0];
        self::assertSame('warning', $scResult->status);
    }

    #[Test]
    public function validateEmptyRulesReturnsNoResults(): void
    {
        $results = $this->validator->validate([]);
        self::assertSame([], $results);
    }

    #[Test]
    public function validateNoFieldsPassesWithMessage(): void
    {
        $rule = $this->createRule(fields: []);
        $results = $this->validator->validate([$rule]);

        $fieldResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'fields_exist');
        $fieldResult = array_values($fieldResults)[0];

        self::assertSame('pass', $fieldResult->status);
        self::assertStringContainsString('No field restrictions', $fieldResult->message);
    }

    #[Test]
    public function validateWildcardClassFieldsWarns(): void
    {
        $rule = $this->createRule(class: '*', fields: ['images']);
        $results = $this->validator->validate([$rule]);

        $fieldResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'fields_exist');
        $fieldResult = array_values($fieldResults)[0];

        self::assertSame('warning', $fieldResult->status);
        self::assertStringContainsString('Cannot validate fields for wildcard', $fieldResult->message);
    }

    #[Test]
    public function validateCallbackMissingForCallbackStrategyFails(): void
    {
        $rule = $this->createRule(strategy: MoveStrategy::Callback, callback: null);
        $results = $this->validator->validate([$rule]);

        $cbResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'callback_service');
        $cbResult = array_values($cbResults)[0];

        self::assertSame('fail', $cbResult->status);
        self::assertStringContainsString('requires a callback service ID', $cbResult->message);
    }

    #[Test]
    public function validateNonCallbackStrategySkipsCallbackCheck(): void
    {
        $rule = $this->createRule(strategy: MoveStrategy::Always, callback: null);
        $results = $this->validator->validate([$rule]);

        $cbResults = array_filter($results, static fn (ValidationResult $r) => $r->check === 'callback_service');
        self::assertEmpty($cbResults);
    }

    #[Test]
    public function validateMultipleRulesReturnsResultsForAll(): void
    {
        $r1 = $this->createRule(name: 'rule_1');
        $r2 = $this->createRule(name: 'rule_2');

        $results = $this->validator->validate([$r1, $r2]);

        $ruleNames = array_unique(array_map(static fn (ValidationResult $r) => $r->ruleName, $results));
        // Should contain results for both rules (and possibly "rule_1, rule_2" for duplicate priority)
        self::assertContains('rule_1', $ruleNames);
        self::assertContains('rule_2', $ruleNames);
    }
}
