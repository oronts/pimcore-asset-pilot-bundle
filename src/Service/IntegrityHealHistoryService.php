<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatterInterface;
use Oronts\AssetPilotBundle\Enum\UndoHealReason;

class IntegrityHealHistoryService implements IntegrityHealHistoryServiceInterface
{
    public function __construct(
        private readonly IntegrityHealLog $healLog,
        private readonly UndoHealEligibilityProbeInterface $eligibilityProbe,
        private readonly ApiDateFormatterInterface $dates,
    ) {}

    /**
     * @return array{
     *     items: list<array{id: int, assetId: int, path: string, fromVersion: int, toVersion: ?int, checker: string, status: string, createdAt: string, eligible: bool, eligibilityReason: ?string, reason: ?string}>,
     *     total: ?int,
     *     page: int,
     *     pages: ?int,
     *     hasMore: bool,
     *     truncated: bool
     * }
     */
    public function getPaginated(int $page = 1, int $limit = 25): array
    {
        $history = $this->healLog->getReversibleHistory($page, $limit);

        return [
            ...$history,
            'items' => array_map(fn (array $row): array => $this->withEligibility($row), $history['items']),
        ];
    }

    /**
     * @param array{id: int, asset_id: int, path: string, from_version: int, to_version: ?int, checker: string, status: string, created_at: string, is_current: bool} $row
     * @return array{id: int, assetId: int, path: string, fromVersion: int, toVersion: ?int, checker: string, status: string, createdAt: string, eligible: bool, eligibilityReason: ?string, reason: ?string}
     */
    private function withEligibility(array $row): array
    {
        $reason = null;
        $reasonCode = null;
        $eligible = false;

        if ($row['status'] === IntegrityHealLog::STATUS_UNDONE) {
            $reason = 'This heal has already been undone.';
            $reasonCode = UndoHealReason::AlreadyUndone;
        } elseif (!$row['is_current']) {
            $reason = 'A newer reversible heal supersedes this record.';
            $reasonCode = UndoHealReason::Superseded;
        } else {
            $assessment = $this->eligibilityProbe->assessUndoEligibility($row['asset_id'], $row['from_version']);
            $eligible = $assessment->isSuccessful();
            if (!$eligible) {
                $reason = $assessment->reason;
                $reasonCode = $assessment->reasonCode ?? UndoHealReason::NoReversibleHeal;
            }
        }

        return [
            'id' => $row['id'],
            'assetId' => $row['asset_id'],
            'path' => $row['path'],
            'fromVersion' => $row['from_version'],
            'toVersion' => $row['to_version'],
            'checker' => $row['checker'],
            'status' => $row['status'],
            'createdAt' => $this->dates->fromDatabase($row['created_at']),
            'eligible' => $eligible,
            'eligibilityReason' => $reasonCode?->value,
            'reason' => $reason,
        ];
    }
}
