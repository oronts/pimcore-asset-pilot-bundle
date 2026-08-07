<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

/**
 * One atomic read of whether an asset is referenced and whether any projection source is still dirty.
 * Both predicates come from a single SQL statement so a deletion safety check cannot observe "no edge"
 * and "no dirty source" on opposite sides of a concurrent projection refresh, which commits the new edge
 * and the source's dirty->clean transition in one transaction.
 */
readonly class DependencyReferenceSnapshot
{
    public function __construct(
        public bool $referenced,
        public bool $dirty,
    ) {}
}
