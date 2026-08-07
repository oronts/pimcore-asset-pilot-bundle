<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Exception;

class MergeLeaseLostException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $runId = null,
        public readonly ?string $rootRunId = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function forRun(string $runId, ?string $rootRunId, string $message, ?\Throwable $previous = null): self
    {
        return new self($message, 0, $previous, $runId, $rootRunId);
    }
}
