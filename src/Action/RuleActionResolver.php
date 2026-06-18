<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Action;

/**
 * Resolves a rule-action `type` to the tagged service that handles it. Each action self-declares its
 * type via getType(), so no alias is needed in the service config.
 */
class RuleActionResolver
{
    /** @var array<string, RuleActionInterface> */
    private array $byType = [];

    /**
     * @param iterable<RuleActionInterface> $actions
     */
    public function __construct(iterable $actions)
    {
        foreach ($actions as $action) {
            $this->byType[$action->getType()] = $action;
        }
    }

    public function resolve(string $type): ?RuleActionInterface
    {
        return $this->byType[$type] ?? null;
    }
}
