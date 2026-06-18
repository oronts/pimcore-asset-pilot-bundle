<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\DependencyInjection;

use Oronts\AssetPilotBundle\Enum\CollisionPattern;
use Oronts\AssetPilotBundle\Service\AssetProtection;
use Oronts\AssetPilotBundle\Service\ConfidenceScorer;
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
                ->arrayNode('allowed_classes')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('Global allowlist of DataObject class names the save listener reacts to. Empty means all classes; rules still gate per class.')
                ->end()
                ->arrayNode('locales')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('Restrict localized-field scanning to these locales. Empty means all valid Pimcore languages.')
                ->end()
            ->end();

        $this->addRulesSection($rootNode);
        $this->addNamingSection($rootNode);
        $this->addAsyncSection($rootNode);
        $this->addAuditSection($rootNode);
        $this->addProtectionSection($rootNode);
        $this->addConfidenceSection($rootNode);
        $this->addQuarantineSection($rootNode);
        $this->addIntegritySection($rootNode);
        $this->addContentScanSection($rootNode);

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
                            ->variableNode('options')
                                ->defaultValue([])
                                ->info('Free-form per-rule options passed to custom filters and strategies via $rule->options.')
                                ->validate()
                                    ->ifTrue(static fn (mixed $v): bool => !is_array($v))
                                    ->thenInvalid('The rule "options" key must be an array of key/value pairs.')
                                ->end()
                            ->end()
                            ->arrayNode('actions')
                                ->info('Post-move actions applied to the asset, each resolved by its "type" via a tagged oronts_asset_pilot.rule_action service (built-in: set_property).')
                                ->arrayPrototype()
                                    ->ignoreExtraKeys(false)
                                    ->children()
                                        ->scalarNode('type')->isRequired()->cannotBeEmpty()->end()
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

    protected function addNamingSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('naming')
                    ->addDefaultsIfNotSet()
                    ->info('Asset naming and collision handling settings.')
                    ->children()
                        ->enumNode('collision_pattern')
                            ->values(CollisionPattern::values())
                            ->defaultValue(CollisionPattern::Counter->value)
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

    protected function addProtectionSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('protection')
                    ->addDefaultsIfNotSet()
                    ->info('Asset protection settings to prevent unwanted organization.')
                    ->children()
                        ->arrayNode('exclude_folders')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Asset folders excluded from organization (e.g. ["/Protected/", "/Manual/"]).')
                        ->end()
                        ->scalarNode('lock_property')
                            ->defaultValue(AssetProtection::DEFAULT_LOCK_PROPERTY)
                            ->info('Custom property name that locks an asset from organization.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addIntegritySection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('integrity')
                    ->addDefaultsIfNotSet()
                    ->info('Asset integrity / self-heal settings (broken-binary detection).')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable integrity detection.')
                        ->end()
                        ->arrayNode('skip_extensions')
                            ->scalarPrototype()->end()
                            ->defaultValue(['svg'])
                            ->info('Extensions skipped during integrity scans (e.g. svg, which render tools handle inconsistently).')
                        ->end()
                        ->enumNode('on_unrecoverable')
                            ->values(['report', 'quarantine'])
                            ->defaultValue('report')
                            ->info('What to do with a broken asset that has no renderable version: report only (log + heal-log row), or also best-effort quarantine it (only if still unused).')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addContentScanSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('content_scan')
                    ->addDefaultsIfNotSet()
                    ->info('Opt-in guard: before deleting/moving an "unused" asset, also scan rich-text/text fields of these classes for a hard-coded reference to its path (which the dependency table misses).')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Enable the content-reference delete/move guard.')
                        ->end()
                        ->arrayNode('classes')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('DataObject class names whose wysiwyg/textarea/input fields are scanned. Empty = the guard is inert.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addQuarantineSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('quarantine')
                    ->addDefaultsIfNotSet()
                    ->info('Quarantine (soft-delete) settings for the unused-asset cleanup path.')
                    ->children()
                        ->scalarNode('folder')
                            ->defaultValue('/Quarantine')
                            ->cannotBeEmpty()
                            ->info('Asset folder quarantined assets are moved to instead of being deleted.')
                        ->end()
                        ->integerNode('grace_days')
                            ->defaultValue(30)
                            ->min(0)
                            ->info('Days a quarantined asset is kept before the purge task may hard-delete it (if still unused).')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addConfidenceSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('confidence')
                    ->addDefaultsIfNotSet()
                    ->info('Day thresholds for unused-asset confidence scoring.')
                    ->children()
                        ->integerNode('recently_uploaded_days')
                            ->defaultValue(ConfidenceScorer::RECENTLY_UPLOADED_DAYS)
                            ->min(1)
                            ->info('Assets modified within this many days are classified "recently uploaded".')
                        ->end()
                        ->integerNode('probably_unused_days')
                            ->defaultValue(ConfidenceScorer::PROBABLY_UNUSED_DAYS)
                            ->min(1)
                            ->info('Assets older than recently_uploaded_days but within this window are "probably unused"; older still are "definitely unused".')
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $c): bool => $c['probably_unused_days'] <= $c['recently_uploaded_days'])
                        ->thenInvalid('confidence.probably_unused_days must be greater than confidence.recently_uploaded_days, otherwise the "probably unused" bucket can never be reached.')
                    ->end()
                ->end()
            ->end();
    }
}
