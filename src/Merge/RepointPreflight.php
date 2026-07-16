<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Merge;

final readonly class RepointPreflight
{
    /**
     * @param list<ReferrerSnapshot> $referrers
     * @param list<string>           $blocked
     */
    public function __construct(
        public int $fromAssetId,
        public int $toAssetId,
        public array $referrers,
        public array $blocked = [],
    ) {}

    /** @return array<string, ReferrerSnapshot> */
    public function referrersByKey(): array
    {
        $indexed = [];
        foreach ($this->referrers as $referrer) {
            $indexed[$referrer->key()] = $referrer;
        }
        ksort($indexed, SORT_STRING);

        return $indexed;
    }

    /** @return list<array{type: string, id: int, fingerprint: string}> */
    public function serializedReferrers(): array
    {
        return array_map(static fn (ReferrerSnapshot $referrer): array => $referrer->toArray(), $this->referrers);
    }
}
