<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command\Support;

use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Symfony\Component\Console\Style\SymfonyStyle;

trait UsesReviewedApplyPlan
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

    private function claimPlan(SymfonyStyle $io, string $token, ApplyPlan $plan): bool
    {
        $status = $this->applyPlans->claim($token, $plan);
        if ($status === ApplyPlanStatus::Claimed) {
            return true;
        }

        $io->error($status === ApplyPlanStatus::Malformed
            ? 'The apply plan token is malformed.'
            : 'The apply plan is stale or was already used. Preview again.');

        return false;
    }

    private function renderPlanToken(SymfonyStyle $io, string $token): void
    {
        $io->writeln('<info>Plan token:</info> ' . $token);
        $io->note('Apply this exact reviewed selection with --apply --plan-token=... before the token expires.');
    }

    /** @param array<string, mixed> $descriptor */
    private function descriptorFingerprint(array $descriptor): string
    {
        return hash('sha256', json_encode($descriptor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
