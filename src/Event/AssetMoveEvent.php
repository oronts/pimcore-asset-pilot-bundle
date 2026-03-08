<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Event;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Symfony\Contracts\EventDispatcher\Event;

class AssetMoveEvent extends Event
{
    protected bool $cancelled = false;

    public function __construct(
        public readonly Asset $asset,
        public readonly string $sourcePath,
        public readonly string $targetPath,
        public readonly AbstractObject $object,
        public readonly Rule $rule,
        public readonly TriggerType $triggerType,
    ) {}

    public function cancel(): void
    {
        $this->cancelled = true;
        $this->stopPropagation();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
