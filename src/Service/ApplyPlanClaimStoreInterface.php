<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface ApplyPlanClaimStoreInterface
{
    /**
     * Atomically reserves a signed plan claim until its expiry.
     *
     * @return bool true only for the caller that creates the claim
     */
    public function claim(
        string $claimId,
        \DateTimeImmutable $claimedAt,
        \DateTimeImmutable $expiresAt,
    ): bool;
}
