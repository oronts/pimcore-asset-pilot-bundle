<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Exception;

/**
 * Thrown when a durable organization run was created but its Messenger dispatch failed. The queued
 * items have already been compensated (failed) and the run finished; {@see $runId} lets the caller
 * report the durable run it can inspect.
 */
class OrganizationRunDispatchException extends \RuntimeException
{
    public function __construct(
        public readonly string $runId,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
