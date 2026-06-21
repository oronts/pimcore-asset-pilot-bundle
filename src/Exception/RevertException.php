<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Exception;

use Oronts\AssetPilotBundle\Enum\RevertFailure;

/**
 * Thrown by OperationReverter when a revert cannot proceed. Carries the machine-readable reason and
 * any context (e.g. the conflicting paths) so a caller can react without parsing the message.
 */
final class RevertException extends \RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly RevertFailure $reason,
        string $message,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @param array<string, mixed> $context */
    public static function of(RevertFailure $reason, string $message, array $context = [], ?\Throwable $previous = null): self
    {
        return new self($reason, $message, $context, $previous);
    }
}
