<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Fixtures;

use OpenApi\Attributes as OA;

#[OA\Info(title: 'Asset Pilot test specification', version: '2.0.0')]
final class OpenApiTestInfo
{
    private function __construct() {}
}
