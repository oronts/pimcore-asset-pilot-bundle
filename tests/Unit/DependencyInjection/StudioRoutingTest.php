<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class StudioRoutingTest extends TestCase
{
    #[Test]
    public function assetPilotApiFollowsTheConfiguredStudioPrefix(): void
    {
        $routing = Yaml::parseFile(dirname(__DIR__, 3) . '/config/pimcore/routing.yaml');

        self::assertSame(
            '%pimcore_studio_backend.url_prefix%/asset-pilot',
            $routing['oronts_asset_pilot_api']['prefix'],
        );
    }

    #[Test]
    public function openApiMarkerClassesAreExcludedFromServiceDiscovery(): void
    {
        $services = Yaml::parseFile(
            dirname(__DIR__, 3) . '/src/Resources/config/services.yaml',
            Yaml::PARSE_CUSTOM_TAGS,
        );
        $definitions = $services['services'];

        foreach (['OpenApiSpecification.php', 'OpenApiPaths.php'] as $excluded) {
            self::assertContains(
                '../../Controller/Api/' . $excluded,
                $definitions['Oronts\\AssetPilotBundle\\']['exclude'],
            );
            self::assertContains(
                '../../Controller/Api/' . $excluded,
                $definitions['Oronts\\AssetPilotBundle\\Controller\\Api\\']['exclude'],
            );
        }
    }
}
