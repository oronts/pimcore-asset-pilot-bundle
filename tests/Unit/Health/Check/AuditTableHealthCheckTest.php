<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health\Check;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\Check\AuditTableHealthCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AuditTableHealthCheck::class)]
class AuditTableHealthCheckTest extends TestCase
{
    private function check(?bool $exists, bool $throws = false): AuditTableHealthCheck
    {
        $logger = $this->createMock(AuditLoggerInterface::class);
        $logger->method('isEnabled')->willReturn(true);

        return new class ($this->createMock(Connection::class), $logger, $exists, $throws) extends AuditTableHealthCheck {
            public function __construct(Connection $c, AuditLoggerInterface $l, private ?bool $exists, private bool $throws)
            {
                parent::__construct($c, $l, new NullLogger());
            }

            protected function tableExists(): bool
            {
                if ($this->throws) {
                    throw new \RuntimeException('schema introspection failed');
                }

                return (bool) $this->exists;
            }
        };
    }

    #[Test]
    public function okWhenTheAuditTableExists(): void
    {
        self::assertSame(HealthStatus::Ok, $this->check(true)->run()->status);
    }

    #[Test]
    public function criticalWhenTheAuditTableIsMissing(): void
    {
        self::assertSame(HealthStatus::Critical, $this->check(false)->run()->status);
    }

    #[Test]
    public function warningWhenTheTableCannotBeVerified(): void
    {
        self::assertSame(HealthStatus::Warning, $this->check(null, throws: true)->run()->status);
    }

    #[Test]
    public function okWhenAuditingIsDisabledRegardlessOfTable(): void
    {
        $logger = $this->createMock(AuditLoggerInterface::class);
        $logger->method('isEnabled')->willReturn(false);

        $check = new class ($this->createMock(Connection::class), $logger, new NullLogger()) extends AuditTableHealthCheck {
            protected function tableExists(): bool
            {
                return false;
            }
        };

        self::assertSame(HealthStatus::Ok, $check->run()->status);
    }
}
