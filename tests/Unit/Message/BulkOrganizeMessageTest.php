<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Message;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BulkOrganizeMessage::class)]
class BulkOrganizeMessageTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $ids = [1, 2, 3];
        $message = new BulkOrganizeMessage(
            objectIds: $ids,
            triggerType: TriggerType::BulkOperation,
        );

        self::assertSame($ids, $message->objectIds);
        self::assertSame(TriggerType::BulkOperation, $message->triggerType);
    }

    #[Test]
    public function acceptsEmptyObjectIds(): void
    {
        $message = new BulkOrganizeMessage(
            objectIds: [],
            triggerType: TriggerType::Manual,
        );

        self::assertSame([], $message->objectIds);
    }

    #[Test]
    public function acceptsSingleObjectId(): void
    {
        $message = new BulkOrganizeMessage(
            objectIds: [99],
            triggerType: TriggerType::Scheduled,
        );

        self::assertCount(1, $message->objectIds);
        self::assertSame(99, $message->objectIds[0]);
    }
}
