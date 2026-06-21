<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\DependencyInjection;

use Oronts\AssetPilotBundle\Action\RuleActionInterface;
use Oronts\AssetPilotBundle\Engine\RuleProviderInterface;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Integrity\IntegrityCheckerInterface;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Notification\NotifierInterface;
use Oronts\AssetPilotBundle\PathResolver\ContextProviderInterface;
use Oronts\AssetPilotBundle\Zip\ZipEntryStrategyInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class OrontsAssetPilotExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Extension seams a consumer plugs into by implementing a bundle-owned interface. Registered for
     * container-wide autoconfiguration (not services.yaml `_instanceof`, which is file-scoped and so
     * would only tag the bundle's own services) so a project service implementing one is auto-tagged,
     * exactly as the docs promise.
     *
     * Tagged explicitly elsewhere, by design: `strategy` (keyed by an `alias` tag attribute that
     * autoconfiguration cannot supply), `filter` (the built-in implementations are tagged in
     * services.yaml), and the Twig / ExpressionLanguage provider seams (framework interfaces, so
     * autoconfiguring them would tag every such service in the project).
     */
    private const array AUTOCONFIGURED_SEAMS = [
        RuleProviderInterface::class => 'oronts_asset_pilot.rule_provider',
        HealthCheckInterface::class => 'oronts_asset_pilot.health_check',
        RuleActionInterface::class => 'oronts_asset_pilot.rule_action',
        IntegrityCheckerInterface::class => 'oronts_asset_pilot.integrity_checker',
        NotifierInterface::class => 'oronts_asset_pilot.notifier',
        DuplicateMergeStrategyInterface::class => 'oronts_asset_pilot.duplicate_merge_strategy',
        ZipEntryStrategyInterface::class => 'oronts_asset_pilot.zip_strategy',
        ContextProviderInterface::class => 'oronts_asset_pilot.context_provider',
    ];
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('pimcore_studio_ui')) {
            $loader = new YamlFileLoader(
                $container,
                new FileLocator(__DIR__ . '/../../config'),
            );
            $loader->load('studio_ui.yaml');
        }

        if ($container->hasExtension('doctrine_migrations')) {
            $loader = new YamlFileLoader(
                $container,
                new FileLocator(__DIR__ . '/../Resources/config'),
            );
            $loader->load('doctrine_migrations.yml');
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('oronts_asset_pilot.config', $config);
        $container->setParameter('oronts_asset_pilot.enabled', $config['enabled']);
        $container->setParameter('oronts_asset_pilot.allowed_classes', $config['allowed_classes']);
        $container->setParameter('oronts_asset_pilot.locales', $config['locales']);

        // Naming parameters
        $container->setParameter('oronts_asset_pilot.naming.collision_pattern', $config['naming']['collision_pattern']);
        $container->setParameter('oronts_asset_pilot.naming.slugify', $config['naming']['slugify']);

        // Async parameters
        $container->setParameter('oronts_asset_pilot.async.enabled', $config['async']['enabled']);
        $container->setParameter('oronts_asset_pilot.async.batch_size', $config['async']['batch_size']);

        // Audit parameters
        $container->setParameter('oronts_asset_pilot.audit.enabled', $config['audit']['enabled']);
        $container->setParameter('oronts_asset_pilot.audit.retention_days', $config['audit']['retention_days']);

        // Protection parameters
        $container->setParameter('oronts_asset_pilot.protection.exclude_folders', $config['protection']['exclude_folders']);
        $container->setParameter('oronts_asset_pilot.protection.lock_property', $config['protection']['lock_property']);

        // Confidence-scoring thresholds
        $container->setParameter('oronts_asset_pilot.confidence.recently_uploaded_days', $config['confidence']['recently_uploaded_days']);
        $container->setParameter('oronts_asset_pilot.confidence.probably_unused_days', $config['confidence']['probably_unused_days']);

        // Quarantine
        $container->setParameter('oronts_asset_pilot.quarantine.folder', $config['quarantine']['folder']);
        $container->setParameter('oronts_asset_pilot.quarantine.grace_days', $config['quarantine']['grace_days']);

        // Integrity
        $container->setParameter('oronts_asset_pilot.integrity.enabled', $config['integrity']['enabled']);
        $container->setParameter('oronts_asset_pilot.integrity.skip_extensions', $config['integrity']['skip_extensions']);
        $container->setParameter('oronts_asset_pilot.integrity.on_unrecoverable', $config['integrity']['on_unrecoverable']);

        // Content-reference scan (delete/move guard)
        $container->setParameter('oronts_asset_pilot.content_scan.enabled', $config['content_scan']['enabled']);
        $container->setParameter('oronts_asset_pilot.content_scan.classes', $config['content_scan']['classes']);

        // Notifications
        $container->setParameter('oronts_asset_pilot.notifications.enabled', $config['notifications']['enabled']);
        $container->setParameter('oronts_asset_pilot.notifications.failure_rate_threshold', $config['notifications']['failure_rate_threshold']);
        $container->setParameter('oronts_asset_pilot.notifications.recipient_user_ids', $config['notifications']['recipient_user_ids']);
        $container->setParameter('oronts_asset_pilot.notifications.recipient_group_ids', $config['notifications']['recipient_group_ids']);
        $container->setParameter('oronts_asset_pilot.notifications.sender_user_id', $config['notifications']['sender_user_id']);

        // Duplicate merge
        $container->setParameter('oronts_asset_pilot.duplicates.merge_strategy', $config['duplicates']['merge_strategy']);

        // Stats caching (read-only dashboard/metrics/unused-storage panels)
        $container->setParameter('oronts_asset_pilot.cache.stats_ttl', $config['cache']['stats_ttl']);
        $container->setParameter('oronts_asset_pilot.cache.unused_stats_ttl', $config['cache']['unused_stats_ttl']);

        // Download-archive (zip) layout + bounds
        $container->setParameter('oronts_asset_pilot.zip.default_strategy', $config['zip']['default_strategy']);
        $container->setParameter('oronts_asset_pilot.zip.max_assets', $config['zip']['max_assets']);

        // Process rules into Rule objects
        $rules = [];
        foreach ($config['rules'] as $name => $ruleConfig) {
            $ruleConfig['name'] = $name;
            $rules[] = $ruleConfig;
        }
        $container->setParameter('oronts_asset_pilot.rules', $rules);

        foreach (self::AUTOCONFIGURED_SEAMS as $interface => $tag) {
            $container->registerForAutoconfiguration($interface)->addTag($tag);
        }

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config'),
        );
        $loader->load('services.yaml');
    }
}
