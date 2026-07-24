<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Exception;

use Oronts\AssetPilotBundle\Enum\ReviewedSelectionError;

class ReviewedSelectionException extends \RuntimeException
{
    public function __construct(
        public readonly ReviewedSelectionError $error,
        string $message,
        public readonly ?int $objectId = null,
        public readonly ?string $runId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
