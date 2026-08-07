<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Model;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\OperationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationResult::class)]
class OperationResultTest extends TestCase
{
    private function createOperation(OperationStatus $status = OperationStatus::Completed, ?int $durationMs = 50): MoveOperation
    {
        return new MoveOperation(
            assetId: 42,
            sourcePath: '/uploads/img.png',
            targetPath: '/Products/P1/Images/img.png',
            objectId: 10,
            objectClass: 'Product',
            ruleName: 'test_rule',
            status: $status,
            triggerType: TriggerType::Manual,
            durationMs: $durationMs,
        );
    }

    #[Test]
    public function successCreatesCompletedResult(): void
    {
        $op = $this->createOperation();
        $result = OperationResult::success($op);

        self::assertSame(OperationStatus::Completed, $result->status);
        self::assertStringContainsString('42', $result->message);
        self::assertStringContainsString('/Products/P1/Images/img.png', $result->message);
        self::assertSame($op, $result->operation);
        self::assertSame(50, $result->durationMs);
    }

    #[Test]
    public function skippedCreatesSkippedResult(): void
    {
        $op = $this->createOperation(OperationStatus::Skipped);
        $result = OperationResult::skipped('Asset already at target path', $op);

        self::assertSame(OperationStatus::Skipped, $result->status);
        self::assertSame('Asset already at target path', $result->message);
        self::assertSame($op, $result->operation);
    }

    #[Test]
    public function failedCreatesFailedResult(): void
    {
        $op = $this->createOperation(OperationStatus::Failed);
        $result = OperationResult::failed('Permission denied', $op);

        self::assertSame(OperationStatus::Failed, $result->status);
        self::assertSame('Permission denied', $result->message);
        self::assertSame($op, $result->operation);
    }

    #[Test]
    public function observerFailureStillReportsTheCompletedMutation(): void
    {
        $op = $this->createOperation(OperationStatus::CompletedWithObserverError);
        $result = OperationResult::completedWithObserverError('Audit delivery failed', $op);

        self::assertSame(OperationStatus::CompletedWithObserverError, $result->status);
        self::assertSame('Audit delivery failed', $result->message);
        self::assertSame($op, $result->operation);
    }

    #[Test]
    public function resultWithNullDuration(): void
    {
        $op = $this->createOperation(durationMs: null);
        $result = OperationResult::success($op);

        self::assertNull($result->durationMs);
    }
}
