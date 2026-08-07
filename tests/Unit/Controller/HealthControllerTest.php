<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\HealthController;
use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(HealthController::class)]
final class HealthControllerTest extends TestCase
{
    #[Test]
    public function readinessReturnsServiceUnavailableForCriticalHealth(): void
    {
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('run')->willReturn([]);
        $checker->method('overall')->willReturn(HealthStatus::Critical);

        $response = (new HealthController($checker, new NullLogger()))->readiness();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame(['status' => 'critical'], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function readinessKeepsWarningsAvailable(): void
    {
        $checker = $this->createMock(HealthCheckerInterface::class);
        $checker->method('run')->willReturn([]);
        $checker->method('overall')->willReturn(HealthStatus::Warning);

        $response = (new HealthController($checker, new NullLogger()))->readiness();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(['status' => 'warning'], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }
}
