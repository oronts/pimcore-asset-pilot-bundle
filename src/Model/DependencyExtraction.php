<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

/**
 * The asset targets an element resolves to, plus whether that resolution was complete. When `complete` is
 * false (an unresolvable classification key, unexpected value shape, or read error) the target set is only a
 * lower bound, so callers must fail closed: never mark the source clean or return a Safe delete verdict.
 */
final readonly class DependencyExtraction
{
    /** @param list<int> $targetIds */
    public function __construct(
        public array $targetIds,
        public bool $complete,
    ) {}
}
