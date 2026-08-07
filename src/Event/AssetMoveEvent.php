<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Event;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Symfony\Contracts\EventDispatcher\Event;

class AssetMoveEvent extends Event
{
    use CancellableEvent;

    public function __construct(
        public readonly Asset $asset,
        public readonly string $sourcePath,
        public readonly string $targetPath,
        public readonly AbstractObject $object,
        public readonly Rule $rule,
        public readonly TriggerType $triggerType,
        public readonly bool $dryRun = false,
        public readonly ?MoveOperation $operation = null,
        public readonly ?\Throwable $throwable = null,
    ) {}

    /** True when fired from a preview/dry-run: listeners may decide cancellation but must not mutate state. */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
}
