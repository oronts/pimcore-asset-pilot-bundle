<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\IntegrityController;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetIntegrityService;
use Oronts\AssetPilotBundle\Service\IntegrityHealFingerprintService;
use Oronts\AssetPilotBundle\Service\IntegrityHealHistoryService;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(IntegrityController::class)]
class IntegrityControllerHistoryTest extends TestCase
{
    #[Test]
    public function historyClampsPaginationAndReturnsTheServiceResult(): void
    {
        $history = $this->createMock(IntegrityHealHistoryService::class);
        $history->expects(self::once())->method('getPaginated')->with(2, 50)->willReturn([
            'items' => [],
            'total' => 0,
            'page' => 2,
            'pages' => 0,
        ]);

        $response = $this->controller($history)->history(Request::create('/integrity/history', 'GET', ['page' => 2, 'limit' => 100]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['page']);
    }

    #[Test]
    public function historyReturnsStableFailureResponse(): void
    {
        $history = $this->createMock(IntegrityHealHistoryService::class);
        $history->method('getPaginated')->willThrowException(new \RuntimeException('database details'));

        $response = $this->controller($history)->history(Request::create('/integrity/history'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Failed to read integrity heal history.'], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    private function controller(IntegrityHealHistoryService $history): IntegrityController
    {
        return new IntegrityController(
            $this->createMock(AssetIntegrityService::class),
            $this->createMock(VersionRollbackHealer::class),
            $history,
            new NullLogger(),
            $this->createMock(ApplyPlanServiceInterface::class),
            $this->createMock(IntegrityHealFingerprintService::class),
            $this->createMock(ElementAuthorization::class),
        );
    }
}
