<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

final readonly class OperationHandle
{
    public function __construct(
        public int $operationId,
        public OperationIntent $intent,
    ) {
        if ($operationId <= 0) {
            throw new \InvalidArgumentException('An operation handle requires a positive operation ID.');
        }
    }
}
