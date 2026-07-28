<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\DependencyInjection;

use Oronts\AssetPilotBundle\DependencyInjection\OrontsAssetPilotExtension;
use Oronts\AssetPilotBundle\Service\ApplyPlanClaimStoreInterface;
use Oronts\AssetPilotBundle\Service\ApplyPlanService;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\DbalApplyPlanClaimStore;
use Oronts\AssetPilotBundle\Service\OperationRunStore;
use Oronts\AssetPilotBundle\Service\OperationRunStoreInterface;
use Oronts\AssetPilotBundle\Strategy\CallbackDecisionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

#[CoversClass(OrontsAssetPilotExtension::class)]
class OrontsAssetPilotExtensionTest extends TestCase
{
    #[Test]
    public function prependsTheControllerDirectoryToStudioOpenApiScanning(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class () extends Extension {
            public function getAlias(): string
            {
                return 'pimcore_studio_backend';
            }

            public function load(array $configs, ContainerBuilder $container): void {}
        });

        (new OrontsAssetPilotExtension())->prepend($container);

        $config = $container->getExtensionConfig('pimcore_studio_backend');
        self::assertCount(1, $config);
        self::assertSame(
            dirname(__DIR__, 3) . '/src/Controller/Api',
            $config[0]['open_api_scan_paths'][0],
        );
    }

    #[Test]
    public function wiresTheGenericApplyPlanServiceToTheDurableClaimStore(): void
    {
        $container = new ContainerBuilder();

        (new OrontsAssetPilotExtension())->load([], $container);

        self::assertSame(
            ApplyPlanService::class,
            (string) $container->getAlias(ApplyPlanServiceInterface::class),
        );

        self::assertSame(
            DbalApplyPlanClaimStore::class,
            (string) $container->getAlias(ApplyPlanClaimStoreInterface::class),
        );
        self::assertSame(
            '%kernel.secret%',
            $container->getDefinition(ApplyPlanService::class)->getArgument('$secret'),
        );
    }

    #[Test]
    public function aliasesTheOperationRunStoreInterface(): void
    {
        $container = new ContainerBuilder();

        (new OrontsAssetPilotExtension())->load([], $container);

        self::assertSame(
            OperationRunStore::class,
            (string) $container->getAlias(OperationRunStoreInterface::class),
        );
    }

    #[Test]
    public function callbackDecisionServicesAreAutoTagged(): void
    {
        $container = new ContainerBuilder();
        (new OrontsAssetPilotExtension())->load([], $container);

        $instanceof = $container->getAutoconfiguredInstanceof();
        self::assertArrayHasKey(CallbackDecisionInterface::class, $instanceof);
        self::assertTrue($instanceof[CallbackDecisionInterface::class]->hasTag('oronts_asset_pilot.callback'));
    }

    #[Test]
    public function builtInConflictStrategiesHaveOneAliasedTagEach(): void
    {
        $container = new ContainerBuilder();
        (new OrontsAssetPilotExtension())->load([], $container);

        foreach ([
            \Oronts\AssetPilotBundle\Strategy\AlwaysMoveStrategy::class => 'always',
            \Oronts\AssetPilotBundle\Strategy\FirstAssignmentStrategy::class => 'first_assignment',
            \Oronts\AssetPilotBundle\Strategy\CallbackStrategy::class => 'callback',
        ] as $service => $alias) {
            $tags = $container->getDefinition($service)->getTag('oronts_asset_pilot.strategy');
            self::assertSame([['alias' => $alias]], $tags);
        }
    }
}
