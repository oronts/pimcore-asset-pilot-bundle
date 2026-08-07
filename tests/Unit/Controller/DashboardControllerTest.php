<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatter;
use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Controller\Api\DashboardController;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DashboardController::class)]
class DashboardControllerTest extends TestCase
{
    #[Test]
    public function completedTotalIncludesObserverWarnings(): void
    {
        $audit = $this->createMock(AuditQueryInterface::class);
        $audit->method('getStats')->willReturn([
            'completed' => 7,
            'completed_with_observer_error' => 2,
            'failed' => 1,
            'by_class' => ['Product' => 10],
        ]);
        $audit->method('getRecent')->willReturn([]);
        $rules = $this->createMock(RuleEngineInterface::class);
        $rules->method('getRules')->willReturn([]);

        $response = (new DashboardController($audit, $rules, new NullLogger(), new ApiDateFormatter()))->dashboard();
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(9, $payload['totalOrganized']);
        self::assertSame(2, $payload['totalOrganizedWithWarnings']);
        self::assertSame(1, $payload['totalFailed']);
    }

    #[Test]
    public function recentOperationsAreEmittedAsRfc3339Utc(): void
    {
        $audit = $this->createMock(AuditQueryInterface::class);
        $audit->method('getStats')->willReturn([]);
        $audit->method('getRecent')->willReturn([
            ['id' => 1, 'status' => 'completed', 'created_at' => '2026-07-15 10:00:00', 'updated_at' => '2026-07-15 10:00:05', 'committed_at' => '2026-07-15 10:00:06'],
        ]);
        $rules = $this->createMock(RuleEngineInterface::class);
        $rules->method('getRules')->willReturn([]);

        $response = (new DashboardController($audit, $rules, new NullLogger(), new ApiDateFormatter()))->dashboard();
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('2026-07-15T10:00:00+00:00', $payload['recentOperations'][0]['created_at']);
        self::assertSame('2026-07-15T10:00:05+00:00', $payload['recentOperations'][0]['updated_at']);
        self::assertSame('2026-07-15T10:00:06+00:00', $payload['recentOperations'][0]['committed_at']);
    }
}
