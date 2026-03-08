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
}
