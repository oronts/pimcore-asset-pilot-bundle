<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use Oronts\AssetPilotBundle\Service\RetryableInfrastructureFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetryableInfrastructureFailure::class)]
final class RetryableInfrastructureFailureTest extends TestCase
{
    #[Test]
    public function aLostConnectionIsRetryable(): void
    {
        self::assertTrue(RetryableInfrastructureFailure::matches(new ConnectionLost($this->driverException(), null)));
    }

    #[Test]
    public function aDeadlockIsRetryable(): void
    {
        self::assertTrue(RetryableInfrastructureFailure::matches(new DeadlockException($this->driverException(), null)));
    }

    #[Test]
    public function aNoActiveTransactionIsRetryable(): void
    {
        // A connection loss inside transactional() is masked by NoActiveTransaction when the finally-rollback runs.
        self::assertTrue(RetryableInfrastructureFailure::matches(NoActiveTransaction::new()));
    }

    #[Test]
    public function aNonTransientBaseConnectionErrorIsNotRetryable(): void
    {
        self::assertFalse(RetryableInfrastructureFailure::matches(new ConnectionException($this->driverException(), null)));
    }

    #[Test]
    public function anUnrelatedFailureIsNotRetryable(): void
    {
        self::assertFalse(RetryableInfrastructureFailure::matches(new \RuntimeException('boom')));
    }

    private function driverException(): DriverException
    {
        return $this->createMock(DriverException::class);
    }
}
