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
}
