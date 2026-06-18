<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class OrontsAssetPilotExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('pimcore_studio_ui')) {
            $loader = new YamlFileLoader(
                $container,
                new FileLocator(__DIR__ . '/../../config'),
            );
            $loader->load('studio_ui.yaml');
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

        // Process rules into Rule objects
        $rules = [];
        foreach ($config['rules'] as $name => $ruleConfig) {
            $ruleConfig['name'] = $name;
            $rules[] = $ruleConfig;
        }
        $container->setParameter('oronts_asset_pilot.rules', $rules);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config'),
        );
        $loader->load('services.yaml');
    }
}
