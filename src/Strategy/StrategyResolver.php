<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Strategy;

use Oronts\AssetPilotBundle\Model\Rule;
use Psr\Log\LoggerInterface;

class StrategyResolver
{
    /** @var ConflictStrategyInterface[] */
    protected readonly array $strategies;

    /** @param iterable<ConflictStrategyInterface> $strategies */
    public function __construct(
        iterable $strategies,
        protected readonly LoggerInterface $logger,
    ) {
        $this->strategies = $strategies instanceof \Traversable
            ? iterator_to_array($strategies)
            : $strategies;
    }

    public function resolve(Rule $rule): ConflictStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($rule->strategy)) {
                $this->logger->debug('Resolved strategy "{strategy}" for rule "{rule}"', [
                    'strategy' => $rule->strategy->value,
                    'rule' => $rule->name,
                ]);
                return $strategy;
            }
        }

        throw new \RuntimeException(sprintf(
            'No conflict strategy found for "%s" in rule "%s". Available strategies: %s',
            $rule->strategy->value,
            $rule->name,
            implode(', ', array_map(
                static fn (ConflictStrategyInterface $s) => $s::class,
                $this->strategies,
            )),
        ));
    }
}
