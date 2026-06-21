<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

readonly class RuleEvaluation
{
    public function __construct(
        public string $ruleName,
        public bool $matched,
        public ?string $rejectionReason,
        public ?string $conditionExpression,
        public ?bool $conditionResult,
        public ?string $conditionError,
        public ?string $filterDetails,
        public ?string $resolvedPath,
        public int $priority,
        public bool $enabled,
    ) {}

    public function describe(): string
    {
        if ($this->matched) {
            return '-> ' . ($this->resolvedPath ?? '(unknown path)');
        }

        return match ($this->rejectionReason) {
            'disabled' => 'disabled',
            'class_mismatch' => 'class_mismatch: ' . ($this->filterDetails ?? ''),
            'field_mismatch' => 'field_mismatch: ' . ($this->filterDetails ?? ''),
            'condition_failed' => 'condition_failed: ' . ($this->conditionExpression ?? '') .
                ($this->conditionError !== null ? ' (error: ' . $this->conditionError . ')' : ''),
            'filter_rejected' => 'filter_rejected: ' . ($this->filterDetails ?? ''),
            default => $this->rejectionReason ?? 'unknown',
        };
    }
}
