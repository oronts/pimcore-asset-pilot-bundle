<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\MessageHandler;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\MessageHandler\BulkOrganizeHandler;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(BulkOrganizeHandler::class)]
class BulkOrganizeHandlerTest extends TestCase
{
    #[Test]
    public function callsOrganizeBulkWithCorrectArgs(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())
            ->method('organizeBulk')
            ->with([1, 2, 3], TriggerType::BulkOperation)
            ->willReturn([]);

        $handler = new BulkOrganizeHandler($organizer, new NullLogger());
        $message = new BulkOrganizeMessage(
            objectIds: [1, 2, 3],
            triggerType: TriggerType::BulkOperation,
        );

        $handler($message);
    }

    #[Test]
    public function rethrowsExceptionFromOrganizer(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->method('organizeBulk')->willThrowException(new \RuntimeException('bulk failed'));

        $handler = new BulkOrganizeHandler($organizer, new NullLogger());
        $message = new BulkOrganizeMessage(
            objectIds: [1],
            triggerType: TriggerType::BulkOperation,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bulk failed');

        $handler($message);
    }

    #[Test]
    public function handlerIsCallable(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $handler = new BulkOrganizeHandler($organizer, new NullLogger());

        self::assertIsCallable($handler);
    }

    #[Test]
    public function handlesEmptyObjectIds(): void
    {
        $organizer = $this->createMock(AssetOrganizer::class);
        $organizer->expects(self::once())
            ->method('organizeBulk')
            ->with([], TriggerType::Manual)
            ->willReturn([]);

        $handler = new BulkOrganizeHandler($organizer, new NullLogger());
        $message = new BulkOrganizeMessage(
            objectIds: [],
            triggerType: TriggerType::Manual,
        );

        $handler($message);
    }
}
