<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Model;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoveOperation::class)]
class MoveOperationTest extends TestCase
{
    private function createOperation(): MoveOperation
    {
        return new MoveOperation(
            assetId: 42,
            sourcePath: '/uploads/image.png',
            targetPath: '/Products/P001/Images/image.png',
            objectId: 100,
            objectClass: 'Product',
            ruleName: 'product_images',
            status: OperationStatus::Pending,
            triggerType: TriggerType::Manual,
        );
    }

    #[Test]
    public function constructsWithRequiredProperties(): void
    {
        $op = $this->createOperation();

        self::assertSame(42, $op->assetId);
        self::assertSame('/uploads/image.png', $op->sourcePath);
        self::assertSame('/Products/P001/Images/image.png', $op->targetPath);
        self::assertSame(100, $op->objectId);
        self::assertSame('Product', $op->objectClass);
        self::assertSame('product_images', $op->ruleName);
        self::assertSame(OperationStatus::Pending, $op->status);
        self::assertSame(TriggerType::Manual, $op->triggerType);
        self::assertNull($op->errorMessage);
        self::assertNull($op->durationMs);
        self::assertInstanceOf(\DateTimeImmutable::class, $op->createdAt);
    }

    #[Test]
    public function withStatusCreatesNewInstanceWithUpdatedStatus(): void
    {
        $original = $this->createOperation();
        $completed = $original->withStatus(OperationStatus::Completed);

        self::assertSame(OperationStatus::Pending, $original->status);
        self::assertSame(OperationStatus::Completed, $completed->status);
        self::assertSame($original->assetId, $completed->assetId);
        self::assertSame($original->sourcePath, $completed->sourcePath);
        self::assertSame($original->targetPath, $completed->targetPath);
        self::assertSame($original->objectId, $completed->objectId);
        self::assertSame($original->ruleName, $completed->ruleName);
    }

    #[Test]
    public function withStatusPreservesExistingError(): void
    {
        $op = new MoveOperation(
            assetId: 1, sourcePath: '/a', targetPath: '/b', objectId: 1,
            objectClass: 'Product', ruleName: 'r', status: OperationStatus::Failed,
            triggerType: TriggerType::Manual, errorMessage: 'original error',
        );

        $updated = $op->withStatus(OperationStatus::Failed);
        self::assertSame('original error', $updated->errorMessage);
    }

    #[Test]
    public function withStatusOverridesError(): void
    {
        $op = $this->createOperation();
        $failed = $op->withStatus(OperationStatus::Failed, 'Something broke');

        self::assertSame(OperationStatus::Failed, $failed->status);
        self::assertSame('Something broke', $failed->errorMessage);
    }

    #[Test]
    public function withDurationCreatesNewInstanceWithTiming(): void
    {
        $original = $this->createOperation();
        $timed = $original->withDuration(150);

        self::assertNull($original->durationMs);
        self::assertSame(150, $timed->durationMs);
        self::assertSame($original->assetId, $timed->assetId);
        self::assertSame($original->status, $timed->status);
    }
}
