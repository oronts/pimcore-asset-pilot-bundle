<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Action;

use Oronts\AssetPilotBundle\Support\UniqueServiceMap;

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
        $this->byType = UniqueServiceMap::from($actions, static fn (RuleActionInterface $action): string => $action->getType(), 'rule action');
    }

    public function resolve(string $type): ?RuleActionInterface
    {
        return $this->byType[$type] ?? null;
    }
}
