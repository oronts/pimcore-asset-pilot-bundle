<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\RuleSetDiff;

interface RulePortabilityInterface
{
    /** @return array{format_version: int, rules: array<string, array<string, mixed>>} */
    public function export(): array;

    /** @param array{rules?: array<string, mixed>} $artifact */
    public function diff(array $artifact): RuleSetDiff;
}
