<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

readonly class ValidationResult
{
    public function __construct(
        public string $ruleName,
        public string $check,
        public string $status,
        public string $message,
    ) {}
}
