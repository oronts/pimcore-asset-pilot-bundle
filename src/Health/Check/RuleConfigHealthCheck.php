<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health\Check;

use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Oronts\AssetPilotBundle\Service\ConfigValidatorInterface;

/**
 * Runs the same validation as `asset-pilot:validate-config` over the loaded rules: a failing rule
 * is Critical (the pipeline will misbehave), a warning is Warning, all-clear is Ok.
 */
class RuleConfigHealthCheck implements HealthCheckInterface
{
    public function __construct(
        protected readonly RuleEngineInterface $ruleEngine,
        protected readonly ConfigValidatorInterface $configValidator,
    ) {}

    public function name(): string
    {
        return 'rule_config';
    }

    public function run(): HealthCheckResult
    {
        $rules = $this->ruleEngine->getRules();
        if ($rules === []) {
            return new HealthCheckResult($this->name(), HealthStatus::Ok, 'No rules configured.');
        }

        $failures = 0;
        $warnings = 0;
        foreach ($this->configValidator->validate($rules) as $result) {
            if ($result->status === 'fail') {
                ++$failures;
            } elseif ($result->status === 'warning') {
                ++$warnings;
            }
        }

        $details = ['rules' => count($rules), 'failures' => $failures, 'warnings' => $warnings];

        if ($failures > 0) {
            return new HealthCheckResult($this->name(), HealthStatus::Critical, sprintf('%d rule validation failure(s).', $failures), $details);
        }
        if ($warnings > 0) {
            return new HealthCheckResult($this->name(), HealthStatus::Warning, sprintf('%d rule validation warning(s).', $warnings), $details);
        }

        return new HealthCheckResult($this->name(), HealthStatus::Ok, sprintf('%d rule(s) valid.', count($rules)), $details);
    }
}
