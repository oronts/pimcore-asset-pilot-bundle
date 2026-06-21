<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health\Check;

use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\Check\RuleConfigHealthCheck;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\ValidationResult;
use Oronts\AssetPilotBundle\Service\ConfigValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleConfigHealthCheck::class)]
class RuleConfigHealthCheckTest extends TestCase
{
    /** @param ValidationResult[] $validationResults */
    private function check(array $rules, array $validationResults): RuleConfigHealthCheck
    {
        $engine = $this->createMock(RuleEngineInterface::class);
        $engine->method('getRules')->willReturn($rules);

        $validator = $this->createMock(ConfigValidator::class);
        $validator->method('validate')->willReturn($validationResults);

        return new RuleConfigHealthCheck($engine, $validator);
    }

    private function rule(): Rule
    {
        return Rule::fromConfig('r', ['class' => 'Product', 'target_path' => '/P']);
    }

    #[Test]
    public function okWhenNoRulesConfigured(): void
    {
        self::assertSame(HealthStatus::Ok, $this->check([], [])->run()->status);
    }

    #[Test]
    public function okWhenAllChecksPass(): void
    {
        $results = [new ValidationResult('r', 'class_exists', 'pass', 'ok')];

        self::assertSame(HealthStatus::Ok, $this->check([$this->rule()], $results)->run()->status);
    }

    #[Test]
    public function warningWhenAnyCheckWarnsButNoneFail(): void
    {
        $results = [
            new ValidationResult('r', 'class_exists', 'pass', 'ok'),
            new ValidationResult('r', 'duplicate_priority', 'warning', 'dup'),
        ];

        self::assertSame(HealthStatus::Warning, $this->check([$this->rule()], $results)->run()->status);
    }

    #[Test]
    public function criticalWhenAnyCheckFails(): void
    {
        $results = [
            new ValidationResult('r', 'class_exists', 'fail', 'missing class'),
            new ValidationResult('r', 'path_template', 'warning', 'odd'),
        ];

        $result = $this->check([$this->rule()], $results)->run();

        self::assertSame(HealthStatus::Critical, $result->status);
    }
}
