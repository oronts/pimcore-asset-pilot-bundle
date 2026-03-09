<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Message;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrganizeAssetsMessage::class)]
class OrganizeAssetsMessageTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $message = new OrganizeAssetsMessage(
            objectId: 42,
            triggerType: TriggerType::ObjectSave,
        );

        self::assertSame(42, $message->objectId);
        self::assertSame(TriggerType::ObjectSave, $message->triggerType);
    }

    #[Test]
    public function supportsAllTriggerTypes(): void
    {
        foreach (TriggerType::cases() as $type) {
            $message = new OrganizeAssetsMessage(objectId: 1, triggerType: $type);
            self::assertSame($type, $message->triggerType);
        }
    }
}
