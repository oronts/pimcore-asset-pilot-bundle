<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Exception;

class StaleApplyPlanException extends \RuntimeException
{
    public readonly ?int $objectId;

    public function __construct(int|string $objectIdOrMessage)
    {
        $this->objectId = is_int($objectIdOrMessage) ? $objectIdOrMessage : null;

        parent::__construct(is_int($objectIdOrMessage)
            ? sprintf('Object %d changed after the apply plan was issued.', $objectIdOrMessage)
            : $objectIdOrMessage);
    }
}
