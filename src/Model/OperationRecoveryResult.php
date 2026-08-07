<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;

readonly class OperationRecoveryResult
{
    public function __construct(
        public int $operationId,
        public int $assetId,
        public OperationKind $kind,
        public OperationStatus $status,
        public bool $journalUpdated,
        public string $message,
        public string $fingerprint,
    ) {
        if ($operationId <= 0 || $assetId <= 0) {
            throw new \InvalidArgumentException('A recovery result requires positive operation and asset IDs.');
        }
        if (!in_array($status, [OperationStatus::Completed, OperationStatus::Failed, OperationStatus::RecoveryRequired], true)) {
            throw new \InvalidArgumentException(sprintf('Status "%s" is not a recovery classification.', $status->value));
        }
        if ($message === '') {
            throw new \InvalidArgumentException('A recovery result requires a message.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('A recovery result requires a SHA-256 fingerprint.');
        }
    }

    public function isResolved(): bool
    {
        return $this->journalUpdated && $this->status !== OperationStatus::RecoveryRequired;
    }
}
