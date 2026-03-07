<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;

readonly class Rule
{
    public function __construct(
        public string $name,
        public string $class,
        public array $fields,
        public ?string $condition,
        public string $targetPath,
        public MoveStrategy $strategy,
        public ?string $callback,
        public int $priority,
        public bool $enabled,
        public array $filters,
    ) {}

    public static function fromConfig(string $name, array $config): self
    {
        return new self(
            name: $name,
            class: $config['class'],
            fields: $config['fields'] ?? [],
            condition: $config['condition'] ?? null,
            targetPath: $config['target_path'],
            strategy: MoveStrategy::from($config['strategy'] ?? 'always'),
            callback: $config['callback'] ?? null,
            priority: $config['priority'] ?? 0,
            enabled: $config['enabled'] ?? true,
            filters: $config['filters'] ?? [],
        );
    }
}
