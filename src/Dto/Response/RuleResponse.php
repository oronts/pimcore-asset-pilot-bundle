<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Dto\Response;

readonly class RuleResponse
{
    public function __construct(
        public string $name,
        public string $class,
        public array $fields,
        public ?string $condition,
        public string $targetPath,
        public string $strategy,
        public int $priority,
        public bool $enabled,
        public array $filters,
    ) {}
}
