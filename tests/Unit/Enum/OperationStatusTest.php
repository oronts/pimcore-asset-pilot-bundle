<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Enum;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationStatus::class)]
class OperationStatusTest extends TestCase
{
    #[Test]
    public function hasExpectedCases(): void
    {
        $cases = OperationStatus::cases();
        self::assertCount(6, $cases);
        self::assertSame('pending', OperationStatus::Pending->value);
        self::assertSame('in_progress', OperationStatus::InProgress->value);
        self::assertSame('completed', OperationStatus::Completed->value);
        self::assertSame('failed', OperationStatus::Failed->value);
        self::assertSame('skipped', OperationStatus::Skipped->value);
        self::assertSame('action_failed', OperationStatus::ActionFailed->value);
    }
}
