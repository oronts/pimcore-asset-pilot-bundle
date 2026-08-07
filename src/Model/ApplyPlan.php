<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

readonly class ApplyPlan
{
    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $config
     * @param list<ApplyPlanTarget> $targets
     */
    public function __construct(
        public string $kind,
        public ActorContext $actor,
        public array $request,
        public array $config,
        public array $targets,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]*$/D', $kind) !== 1) {
            throw new \InvalidArgumentException('The apply plan kind must be a lowercase identifier.');
        }
        if ($targets === []) {
            throw new \InvalidArgumentException('An apply plan requires at least one target.');
        }

        $targetIds = [];
        foreach ($targets as $target) {
            if (!$target instanceof ApplyPlanTarget) {
                throw new \InvalidArgumentException('Apply plan targets must be ApplyPlanTarget instances.');
            }
            if (isset($targetIds[$target->id])) {
                throw new \InvalidArgumentException(sprintf('Duplicate apply plan target ID "%s".', $target->id));
            }

            $targetIds[$target->id] = true;
        }
    }

    /**
     * The target-id => fingerprint map submitted as the expected-hash set that gates the apply-time
     * optimistic lock (hash_equals) in every reviewed mutation path.
     *
     * @return array<string, string>
     */
    public function fingerprintMap(): array
    {
        $fingerprints = [];
        foreach ($this->targets as $target) {
            $fingerprints[$target->id] = $target->fingerprint;
        }

        return $fingerprints;
    }
}
