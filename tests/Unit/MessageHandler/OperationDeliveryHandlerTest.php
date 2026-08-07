<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\MessageHandler;

use Oronts\AssetPilotBundle\Message\OperationDeliveryMessage;
use Oronts\AssetPilotBundle\MessageHandler\OperationDeliveryHandler;
use Oronts\AssetPilotBundle\Service\OperationDeliveryProcessorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationDeliveryHandler::class)]
final class OperationDeliveryHandlerTest extends TestCase
{
    #[Test]
    public function delegatesTheExactIdToTheAuthoritativeClaimProcessor(): void
    {
        $processor = $this->createMock(OperationDeliveryProcessorInterface::class);
        $processor->expects(self::once())->method('process')->with('stable-id');

        (new OperationDeliveryHandler($processor))(new OperationDeliveryMessage('stable-id'));
    }
}
