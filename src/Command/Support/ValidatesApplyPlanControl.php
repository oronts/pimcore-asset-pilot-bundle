<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command\Support;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The reviewed-apply `--plan-token`/`--apply` argument check, shared by every reviewed-selection command.
 * It has no service dependencies, so commands that do not claim plans directly can use it on its own.
 */
trait ValidatesApplyPlanControl
{
    private function hasValidPlanControl(SymfonyStyle $io, bool $apply, mixed $planToken): bool
    {
        if ($apply && (!is_string($planToken) || trim($planToken) === '')) {
            $io->error('Applying requires --plan-token from a matching preview.');

            return false;
        }
        if (!$apply && is_string($planToken) && trim($planToken) !== '') {
            $io->error('--plan-token is only valid together with --apply.');

            return false;
        }

        return true;
    }
}
