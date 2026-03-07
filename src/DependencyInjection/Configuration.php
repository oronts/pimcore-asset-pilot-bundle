<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('oronts_asset_pilot');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Enable or disable the Asset Pilot engine globally.')
                ->end()
            ->end();

        $this->addRulesSection($rootNode);
        $this->addStrategiesSection($rootNode);
        $this->addNamingSection($rootNode);
        $this->addAsyncSection($rootNode);
        $this->addAuditSection($rootNode);
        $this->addLoggingSection($rootNode);

        return $treeBuilder;
    }

    protected function addRulesSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('rules')
                    ->info('Named rules that define how assets are organized for specific DataObject classes.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('class')
                                ->isRequired()
                                ->cannotBeEmpty()
                                ->info('DataObject class name (e.g. "Product", "Category").')
                            ->end()
                            ->arrayNode('fields')
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                                ->info('List of asset-related field names to process. Empty means all asset fields.')
                            ->end()
                            ->scalarNode('condition')
                                ->defaultNull()
                                ->info('ExpressionLanguage condition evaluated per object. Variable "object" is available.')
                            ->end()
                            ->scalarNode('target_path')
                                ->isRequired()
                                ->cannotBeEmpty()
                                ->info('Path template with placeholders, e.g. "/Products/{object.key}/images".')
                            ->end()
                            ->enumNode('strategy')
                                ->values(['always', 'first_assignment', 'callback'])
                                ->defaultValue('always')
                                ->info('When to move assets: always on save, only on first assignment, or delegate to a callback service.')
                            ->end()
                            ->scalarNode('callback')
                                ->defaultNull()
                                ->info('Service ID for the callback strategy. Required when strategy is "callback".')
                            ->end()
                            ->integerNode('priority')
                                ->defaultValue(10)
                                ->info('Rule priority. Higher values are matched first.')
                            ->end()
                            ->booleanNode('enabled')
                                ->defaultTrue()
                                ->info('Enable or disable this rule individually.')
                            ->end()
                            ->arrayNode('filters')
                                ->addDefaultsIfNotSet()
                                ->info('Optional filters to restrict which assets this rule applies to.')
                                ->children()
                                    ->arrayNode('types')
                                        ->scalarPrototype()->end()
                                        ->defaultValue([])
                                        ->info('Asset types to include (e.g. ["image", "video"]).')
                                    ->end()
                                    ->integerNode('min_size')
                                        ->defaultNull()
                                        ->info('Minimum asset file size in bytes.')
                                    ->end()
                                    ->integerNode('max_size')
                                        ->defaultNull()
                                        ->info('Maximum asset file size in bytes.')
                                    ->end()
                                    ->arrayNode('extensions')
                                        ->scalarPrototype()->end()
                                        ->defaultValue([])
                                        ->info('Allowed file extensions (e.g. ["jpg", "png", "webp"]).')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->validate()
                            ->ifTrue(fn (array $rule): bool => $rule['strategy'] === 'callback' && empty($rule['callback']))
                            ->thenInvalid('The "callback" option is required when strategy is set to "callback".')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addStrategiesSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('strategies')
                    ->addDefaultsIfNotSet()
                    ->info('Global strategy settings.')
                    ->children()
                        ->enumNode('default')
                            ->values(['always', 'first_assignment', 'callback'])
                            ->defaultValue('always')
                            ->info('Default strategy applied when a rule does not specify one.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addNamingSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('naming')
                    ->addDefaultsIfNotSet()
                    ->info('Asset naming and collision handling settings.')
                    ->children()
                        ->enumNode('collision_pattern')
                            ->values(['counter', 'timestamp', 'uuid'])
                            ->defaultValue('counter')
                            ->info('How to resolve filename collisions at the target path.')
                        ->end()
                        ->booleanNode('slugify')
                            ->defaultTrue()
                            ->info('Whether to slugify asset filenames during organization.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addAsyncSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('async')
                    ->addDefaultsIfNotSet()
                    ->info('Asynchronous processing via Symfony Messenger.')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Process asset moves asynchronously via the messenger queue.')
                        ->end()
                        ->integerNode('batch_size')
                            ->defaultValue(50)
                            ->min(1)
                            ->info('Number of asset operations to batch together in a single message.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addAuditSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('audit')
                    ->addDefaultsIfNotSet()
                    ->info('Audit log settings for tracking all asset move operations.')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable audit logging of asset organization operations.')
                        ->end()
                        ->integerNode('retention_days')
                            ->defaultValue(90)
                            ->min(1)
                            ->info('Number of days to retain audit log entries before cleanup.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addLoggingSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('logging')
                    ->addDefaultsIfNotSet()
                    ->info('Logging channel configuration.')
                    ->children()
                        ->scalarNode('channel')
                            ->defaultValue('asset_pilot')
                            ->cannotBeEmpty()
                            ->info('Pimcore/Monolog log channel name.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }
}
