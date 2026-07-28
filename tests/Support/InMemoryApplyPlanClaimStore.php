<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Support;

use Oronts\AssetPilotBundle\Service\ApplyPlanClaimStoreInterface;

final class InMemoryApplyPlanClaimStore implements ApplyPlanClaimStoreInterface
{
    /** @var array<string, \DateTimeImmutable> */
    private array $claims = [];

    public function claim(
        string $claimId,
        \DateTimeImmutable $claimedAt,
        \DateTimeImmutable $expiresAt,
    ): bool {
        foreach ($this->claims as $storedId => $storedExpiry) {
            if ($storedExpiry <= $claimedAt) {
                unset($this->claims[$storedId]);
            }
        }
        if (isset($this->claims[$claimId])) {
            return false;
        }

        $this->claims[$claimId] = $expiresAt;

        return true;
    }
}
