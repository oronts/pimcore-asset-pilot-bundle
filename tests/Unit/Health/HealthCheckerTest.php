<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthChecker;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(HealthChecker::class)]
class HealthCheckerTest extends TestCase
{
    private function check(string $name, HealthStatus $status): HealthCheckInterface
    {
        return new class ($name, $status) implements HealthCheckInterface {
            public function __construct(private string $name, private HealthStatus $status) {}

            public function name(): string
            {
                return $this->name;
            }

            public function run(): HealthCheckResult
            {
                return new HealthCheckResult($this->name, $this->status, 'stub');
            }
        };
    }

    #[Test]
    public function runCollectsEveryCheckResult(): void
    {
        $checker = new HealthChecker([
            $this->check('a', HealthStatus::Ok),
            $this->check('b', HealthStatus::Warning),
        ], new NullLogger());

        $results = $checker->run();

        self::assertCount(2, $results);
        self::assertSame('a', $results[0]->name);
        self::assertSame('b', $results[1]->name);
    }

    #[Test]
    public function aThrowingCheckBecomesCriticalWithoutAbortingTheRunOrLeaking(): void
    {
        $boom = new class () implements HealthCheckInterface {
            public function name(): string
            {
                return 'boom';
            }

            public function run(): HealthCheckResult
            {
                throw new \RuntimeException('SQLSTATE secret internals');
            }
        };

        $results = (new HealthChecker([$boom, $this->check('ok', HealthStatus::Ok)], new NullLogger()))->run();

        self::assertCount(2, $results);
        self::assertSame(HealthStatus::Critical, $results[0]->status);
        self::assertStringNotContainsString('secret', $results[0]->message);
        self::assertSame(HealthStatus::Ok, $results[1]->status);
    }

    #[Test]
    public function overallIsTheWorstStatus(): void
    {
        $results = [
            new HealthCheckResult('a', HealthStatus::Ok, ''),
            new HealthCheckResult('b', HealthStatus::Critical, ''),
            new HealthCheckResult('c', HealthStatus::Warning, ''),
        ];

        self::assertSame(HealthStatus::Critical, (new HealthChecker([], new NullLogger()))->overall($results));
    }

    #[Test]
    public function overallIsOkForNoResults(): void
    {
        self::assertSame(HealthStatus::Ok, (new HealthChecker([], new NullLogger()))->overall([]));
    }

    #[Test]
    public function overallIsWarningWhenWorstIsWarning(): void
    {
        $results = [
            new HealthCheckResult('a', HealthStatus::Ok, ''),
            new HealthCheckResult('b', HealthStatus::Warning, ''),
        ];

        self::assertSame(HealthStatus::Warning, (new HealthChecker([], new NullLogger()))->overall($results));
    }
}
