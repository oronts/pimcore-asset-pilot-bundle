<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Audit;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AuditLogger::class)]
class AuditLoggerTest extends TestCase
{
    #[Test]
    public function persistsTheActingUserId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(self::anything(), self::callback(static fn (array $data): bool => ($data['user_id'] ?? 'missing') === 42));

        (new AuditLogger($connection, new NullLogger()))->log($this->operation(42));
    }

    #[Test]
    public function persistsNullUserIdForAutomatedMoves(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(self::anything(), self::callback(static fn (array $data): bool => array_key_exists('user_id', $data) && $data['user_id'] === null));

        (new AuditLogger($connection, new NullLogger()))->log($this->operation(null));
    }

    #[Test]
    public function doesNotWriteWhenDisabled(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');

        (new AuditLogger($connection, new NullLogger(), enabled: false))->log($this->operation(42));
    }

    private function operation(?int $userId): MoveOperation
    {
        return new MoveOperation(
            assetId: 1,
            sourcePath: '/source/file.jpg',
            targetPath: '/target/file.jpg',
            objectId: 2,
            objectClass: 'Product',
            ruleName: 'revert:images',
            status: OperationStatus::Completed,
            triggerType: TriggerType::Manual,
            userId: $userId,
        );
    }
}
