<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Enum;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TriggerType::class)]
class TriggerTypeTest extends TestCase
{
    #[Test]
    public function hasExpectedCases(): void
    {
        $cases = TriggerType::cases();
        self::assertCount(6, $cases);
        self::assertSame('object_save', TriggerType::ObjectSave->value);
        self::assertSame('bulk_operation', TriggerType::BulkOperation->value);
        self::assertSame('manual', TriggerType::Manual->value);
        self::assertSame('scheduled', TriggerType::Scheduled->value);
        self::assertSame('api', TriggerType::Api->value);
        self::assertSame('asset_upload', TriggerType::AssetUpload->value);
    }
}
