<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatter;
use Oronts\AssetPilotBundle\Controller\Api\StorageController;
use Oronts\AssetPilotBundle\Service\StorageTrendServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(StorageController::class)]
final class StorageControllerTest extends TestCase
{
    #[Test]
    public function trendSeriesEmitsCapturedAtAsRfc3339Utc(): void
    {
        $trends = $this->createMock(StorageTrendServiceInterface::class);
        $trends->method('trend')->willReturn([
            ['capturedAt' => '2026-06-19 00:00:00', 'count' => 0, 'size' => 0, 'unknownSizeCount' => 0],
            ['capturedAt' => '2026-06-18 00:00:00', 'count' => 3, 'size' => 150, 'unknownSizeCount' => 1],
        ]);

        $controller = new StorageController($trends, new NullLogger(), new ApiDateFormatter());
        $response = $controller->trends(Request::create('/storage/trends', 'GET'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('2026-06-19T00:00:00+00:00', $body['items'][0]['capturedAt']);
        self::assertSame('2026-06-18T00:00:00+00:00', $body['items'][1]['capturedAt']);
        self::assertSame(3, $body['items'][1]['count']);
        self::assertSame(1, $body['items'][1]['unknownSizeCount']);
    }
}
