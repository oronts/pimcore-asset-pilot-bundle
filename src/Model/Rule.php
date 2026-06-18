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
        public array $options = [],
        public array $actions = [],
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
            priority: $config['priority'] ?? 10,
            enabled: $config['enabled'] ?? true,
            filters: $config['filters'] ?? [],
            options: $config['options'] ?? [],
            actions: $config['actions'] ?? [],
        );
    }

    /**
     * The inverse of fromConfig(): the per-rule config shape (without the name, which is the
     * rule-set key). Used to export/diff rule sets between environments.
     *
     * @return array<string, mixed>
     */
    public function toConfigArray(): array
    {
        return [
            'class' => $this->class,
            'fields' => $this->fields,
            'condition' => $this->condition,
            'target_path' => $this->targetPath,
            'strategy' => $this->strategy->value,
            'callback' => $this->callback,
            'priority' => $this->priority,
            'enabled' => $this->enabled,
            'filters' => $this->filters,
            'options' => $this->options,
            'actions' => $this->actions,
        ];
    }
}
