<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Action;

interface RuleActionConfigValidatorInterface
{
    /** @return list<string> */
    public function validateConfig(array $config): array;
}
