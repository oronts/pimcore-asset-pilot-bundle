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
}
