<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\IntegrityController;
use Oronts\AssetPilotBundle\Service\AssetIntegrityService;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(IntegrityController::class)]
class IntegrityControllerIdsTest extends TestCase
{
    private function controller(AssetIntegrityService $integrity): IntegrityController
    {
        return new IntegrityController(
            $integrity,
            $this->createMock(VersionRollbackHealer::class),
            new NullLogger(),
        );
    }

    public function testIdsQueryRejectsNonDigitTokensInsteadOfCoercing(): void
    {
        $integrity = $this->createMock(AssetIntegrityService::class);
        // "5abc" must be dropped (not coerced to 5), dupes collapsed, non-positive dropped.
        $integrity->expects(self::once())->method('checkAssets')->with([6, 7])->willReturn([]);

        $response = $this->controller($integrity)->brokenAssets(
            Request::create('/integrity', 'GET', ['ids' => '5abc,6,7,7,-1,0']),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testIdsQueryRejectsAllGarbageWith400(): void
    {
        $integrity = $this->createMock(AssetIntegrityService::class);
        $integrity->expects(self::never())->method('checkAssets');

        $response = $this->controller($integrity)->brokenAssets(
            Request::create('/integrity', 'GET', ['ids' => 'abc,,-3,4.5']),
        );

        self::assertSame(400, $response->getStatusCode());
    }
}
