<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\UndoHealOutcome;
use Oronts\AssetPilotBundle\Enum\UndoHealReason;
use Oronts\AssetPilotBundle\Model\UndoHealResult;
use Oronts\AssetPilotBundle\Service\IntegrityHealHistoryService;
use Oronts\AssetPilotBundle\Service\IntegrityHealLog;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntegrityHealHistoryService::class)]
class IntegrityHealHistoryServiceTest extends TestCase
{
    #[Test]
    public function reportsCurrentEligibilityForEveryHistoryRow(): void
    {
        $log = $this->createMock(IntegrityHealLog::class);
        $log->method('getReversibleHistory')->with(1, 25)->willReturn([
            'items' => [
                $this->row(1, 10, IntegrityHealLog::STATUS_HEALED, true),
                $this->row(2, 20, IntegrityHealLog::STATUS_HEALED, false),
                $this->row(3, 30, IntegrityHealLog::STATUS_UNDONE, false),
                $this->row(4, 40, IntegrityHealLog::STATUS_HEALED, true),
            ],
            'total' => 4,
            'page' => 1,
            'pages' => 1,
        ]);
        $healer = $this->createMock(VersionRollbackHealer::class);
        $healer->method('undoDetailed')->willReturnMap([
            [10, true, new UndoHealResult(UndoHealOutcome::WouldReverse, dryRun: true)],
            [40, true, new UndoHealResult(UndoHealOutcome::Skipped, 'Asset is locked.', true, UndoHealReason::AssetLocked)],
        ]);

        $items = (new IntegrityHealHistoryService($log, $healer))->getPaginated()['items'];

        self::assertTrue($items[0]['eligible']);
        self::assertNull($items[0]['eligibilityReason']);
        self::assertSame(UndoHealReason::Superseded->value, $items[1]['eligibilityReason']);
        self::assertSame(UndoHealReason::AlreadyUndone->value, $items[2]['eligibilityReason']);
        self::assertSame(UndoHealReason::AssetLocked->value, $items[3]['eligibilityReason']);
        self::assertSame('Asset is locked.', $items[3]['reason']);
    }

    /** @return array{id: int, asset_id: int, path: string, from_version: int, to_version: int, checker: string, status: string, created_at: string, is_current: bool} */
    private function row(int $id, int $assetId, string $status, bool $isCurrent): array
    {
        return [
            'id' => $id,
            'asset_id' => $assetId,
            'path' => sprintf('/asset-%d.jpg', $assetId),
            'from_version' => $assetId + 1,
            'to_version' => $assetId + 2,
            'checker' => 'image',
            'status' => $status,
            'created_at' => '2026-07-15 10:00:00',
            'is_current' => $isCurrent,
        ];
    }
}
