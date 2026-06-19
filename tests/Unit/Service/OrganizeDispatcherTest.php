<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

#[CoversClass(OrganizeDispatcher::class)]
class OrganizeDispatcherTest extends TestCase
{
    private function captureDispatch(?Envelope &$captured): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (Envelope $envelope) use (&$captured): Envelope {
                $captured = $envelope;

                return $envelope;
            },
        );

        return $bus;
    }

    #[Test]
    public function dispatchObjectQueuesTheMessageWithTheSingleDedupKey(): void
    {
        $captured = null;
        (new OrganizeDispatcher($this->captureDispatch($captured)))->dispatchObject(42, TriggerType::Api);

        self::assertInstanceOf(Envelope::class, $captured);
        $message = $captured->getMessage();
        self::assertInstanceOf(OrganizeAssetsMessage::class, $message);
        self::assertSame(42, $message->objectId);
        self::assertSame(TriggerType::Api, $message->triggerType);

        $stamp = $captured->last(DeduplicateStamp::class);
        self::assertInstanceOf(DeduplicateStamp::class, $stamp);
        self::assertSame('asset_pilot_organize_42', (string) $stamp->getKey());
        self::assertSame(30.0, $stamp->getTtl());
    }

    #[Test]
    public function dispatchBulkQueuesTheBatchWithTheBulkDedupKey(): void
    {
        $captured = null;
        (new OrganizeDispatcher($this->captureDispatch($captured)))->dispatchBulk([1, 2, 3], TriggerType::BulkOperation);

        self::assertInstanceOf(Envelope::class, $captured);
        $message = $captured->getMessage();
        self::assertInstanceOf(BulkOrganizeMessage::class, $message);
        self::assertSame([1, 2, 3], $message->objectIds);
        self::assertSame(TriggerType::BulkOperation, $message->triggerType);

        $stamp = $captured->last(DeduplicateStamp::class);
        self::assertInstanceOf(DeduplicateStamp::class, $stamp);
        self::assertSame('asset_pilot_bulk_' . md5('1,2,3'), (string) $stamp->getKey());
        self::assertSame(60.0, $stamp->getTtl());
    }

    #[Test]
    public function dispatchBulkSortsIdsSoTheDedupKeyIsOrderIndependent(): void
    {
        $captured = null;
        (new OrganizeDispatcher($this->captureDispatch($captured)))->dispatchBulk([3, 1, 2], TriggerType::BulkOperation);

        self::assertInstanceOf(Envelope::class, $captured);
        self::assertSame([1, 2, 3], $captured->getMessage()->objectIds);
        self::assertSame('asset_pilot_bulk_' . md5('1,2,3'), (string) $captured->last(DeduplicateStamp::class)->getKey());
    }
}
