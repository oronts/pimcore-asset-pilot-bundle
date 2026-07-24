<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Condition\ConditionEvaluatorInterface;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\ValidationResult;
use Oronts\AssetPilotBundle\PathResolver\PathResolverInterface;
use Oronts\AssetPilotBundle\Service\ConfigValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Container\ContainerInterface;

#[CoversClass(ConfigValidator::class)]
final class ConfigValidatorExtensionContractTest extends TestCase
{
    #[Test]
    public function validatorDelegatesSyntaxChecksToConsumerReplacements(): void
    {
        $conditions = new RecordingConditionEvaluator();
        $paths = new RecordingPathResolver();
        $validator = new ConfigValidator(
            $this->createMock(ContainerInterface::class),
            $conditions,
            $paths,
        );
        $rule = Rule::fromConfig('consumer_extensions', [
            'class' => '*',
            'condition' => 'consumer_language:approved',
            'target_path' => 'db-map://product-images',
        ]);

        $results = $validator->validate([$rule]);

        self::assertSame(['consumer_language:approved'], $conditions->validatedExpressions);
        self::assertSame(['db-map://product-images'], $paths->validatedTemplates);
        self::assertSame('pass', self::validationResult($results, 'condition_syntax')->status);
        self::assertSame('pass', self::validationResult($results, 'path_template')->status);
    }

    #[Test]
    public function validatorReportsConsumerReplacementErrorsWithoutFallingBackToBuiltIns(): void
    {
        $conditions = new RecordingConditionEvaluator('The workflow expression is unknown.');
        $paths = new RecordingPathResolver('The database mapping does not exist.');
        $validator = new ConfigValidator(
            $this->createMock(ContainerInterface::class),
            $conditions,
            $paths,
        );
        $rule = Rule::fromConfig('consumer_extensions', [
            'class' => '*',
            'condition' => 'workflow:missing',
            'target_path' => 'db-map://missing',
        ]);

        $results = $validator->validate([$rule]);

        $condition = self::validationResult($results, 'condition_syntax');
        $path = self::validationResult($results, 'path_template');
        self::assertSame('fail', $condition->status);
        self::assertStringContainsString('workflow expression is unknown', $condition->message);
        self::assertSame('fail', $path->status);
        self::assertStringContainsString('database mapping does not exist', $path->message);
    }

    /** @param list<ValidationResult> $results */
    private static function validationResult(array $results, string $check): ValidationResult
    {
        foreach ($results as $result) {
            if ($result->check === $check) {
                return $result;
            }
        }

        self::fail(sprintf('Validation result %s was not produced.', $check));
    }
}

final class RecordingConditionEvaluator implements ConditionEvaluatorInterface
{
    /** @var list<string> */
    public array $validatedExpressions = [];

    public function __construct(private readonly ?string $validationError = null) {}

    public function evaluate(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): bool
    {
        return true;
    }

    public function evaluateStrict(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): bool
    {
        return true;
    }

    public function validateSyntax(string $expression): void
    {
        $this->validatedExpressions[] = $expression;
        if ($this->validationError !== null) {
            throw new \InvalidArgumentException($this->validationError);
        }
    }
}

final class RecordingPathResolver implements PathResolverInterface
{
    /** @var list<string> */
    public array $validatedTemplates = [];

    public function __construct(private readonly ?string $validationError = null) {}

    public function resolve(AbstractObject $object, Asset $asset, Rule $rule, ?string $locale = null): string
    {
        return '/Consumer/Resolved';
    }

    public function validateTemplate(string $template): void
    {
        $this->validatedTemplates[] = $template;
        if ($this->validationError !== null) {
            throw new \InvalidArgumentException($this->validationError);
        }
    }
}
