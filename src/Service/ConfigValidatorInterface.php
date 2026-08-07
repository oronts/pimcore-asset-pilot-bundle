<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\ValidationResult;

interface ConfigValidatorInterface
{
    /** @return list<ValidationResult> */
    public function validate(array $rules): array;
}
