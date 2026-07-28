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
                    ->info('Enable automatic organization from Pimcore object-save and asset-upload events. Explicit API, CLI, queue, and maintenance operations remain available.')
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
        $this->addIdempotencySection($rootNode);
        $this->addOperationJournalSection($rootNode);
        $this->addOperationRunsSection($rootNode);
        $this->addAuditSection($rootNode);
        $this->addProtectionSection($rootNode);
        $this->addConfidenceSection($rootNode);
        $this->addQuarantineSection($rootNode);
        $this->addIntegritySection($rootNode);
        $this->addContentScanSection($rootNode);
        $this->addDependencyProjectionSection($rootNode);
        $this->addStorageSection($rootNode);
        $this->addNotificationsSection($rootNode);
        $this->addDuplicatesSection($rootNode);
        $this->addCacheSection($rootNode);

        return $treeBuilder;
    }

    protected function addStorageSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('storage_snapshots')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('minimum_interval_seconds')->defaultValue(3600)->min(0)->end()
                        ->integerNode('retention_days')->defaultValue(365)->min(0)->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addCacheSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('stats_ttl')
                            ->defaultValue(60)
                            ->min(0)
                            ->info('TTL (seconds) for the audit-log stats cache (dashboard/metrics). 0 disables caching (always live).')
                        ->end()
                        ->integerNode('unused_stats_ttl')
                            ->defaultValue(300)
                            ->min(0)
                            ->info('TTL (seconds) for the unused-asset storage-stats cache on the web endpoint. 0 disables (always live).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('zip')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default_strategy')
                            ->defaultValue('flat')
                            ->info('Default download-archive layout: flat, folder, type, or a custom oronts_asset_pilot.zip_strategy name.')
                        ->end()
                        ->integerNode('max_assets')
                            ->defaultValue(1000)
                            ->min(1)
                            ->info('Maximum number of assets packed into one download archive.')
                        ->end()
                        ->integerNode('max_uncompressed_bytes')
                            ->defaultValue(536870912)
                            ->min(1)
                            ->info('Maximum total source bytes packed into one archive.')
                        ->end()
                        ->integerNode('download_token_ttl')
                            ->defaultValue(300)
                            ->min(1)
                            ->info('Lifetime in seconds for a user-bound native browser download token.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('listing')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('scan_budget')
                            ->defaultValue(5000)
                            ->min(1)
                            ->info('Maximum raw candidate rows an authorized listing scans per page while filling it past native-permission denials. A page that cannot be resolved within this budget is reported as truncated.')
                        ->end()
                        ->integerNode('batch_size')
                            ->defaultValue(100)
                            ->min(1)
                            ->info('Raw rows fetched per window while an authorized listing scans and fills a page.')
                        ->end()
                        ->integerNode('export_max_rows')
                            ->defaultValue(200000)
                            ->min(1)
                            ->info('Maximum rows an authorized CSV export streams before it stops and appends a truncation marker row.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addDuplicatesSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('duplicates')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('merge_strategy')
                            ->defaultValue('quarantine')
                            ->cannotBeEmpty()
                            ->info('Default disposition for a duplicate merge: a registered strategy name (built-in: quarantine, delete, isolate). Custom strategies tagged oronts_asset_pilot.duplicate_merge_strategy are selectable too.')
                        ->end()
                        ->integerNode('group_scan_budget')
                            ->defaultValue(5000)
                            ->min(1)
                            ->info('Maximum duplicate groups the listing scans per page while filling it past natively-hidden groups. A page that cannot be resolved within this budget is reported as truncated.')
                        ->end()
                        ->integerNode('export_group_scan_budget')
                            ->defaultValue(500000)
                            ->min(1)
                            ->info('Maximum duplicate groups the CSV export scans before it stops and appends a truncation marker row.')
                        ->end()
                    ->end()
                ->end()
            ->end();
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
                                ->info('ExpressionLanguage condition evaluated per object. Variables "object", "asset", "rule" and "locale" are available.')
                            ->end()
                            ->arrayNode('locales')
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                                ->info('Restrict this rule to these locales (for localized asset fields). Empty means all locales; the rule then also applies to non-localized fields.')
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
                        ->integerNode('worker_heartbeat_max_age')
                            ->defaultValue(120)
                            ->min(5)
                            ->info('Maximum worker heartbeat age in seconds before health becomes critical.')
                        ->end()
                        ->scalarNode('transport')
                            ->defaultValue('asset_pilot')
                            ->cannotBeEmpty()
                            ->info('Messenger transport that receives Asset Pilot organization messages.')
                        ->end()
                        ->scalarNode('failure_transport')
                            ->defaultValue('asset_pilot_failed')
                            ->cannotBeEmpty()
                            ->info('Messenger failure transport inspected by the health check.')
                        ->end()
                        ->integerNode('max_queue_depth')
                            ->defaultValue(1000)
                            ->min(1)
                            ->info('Queue depth above which the health check reports a warning.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addIdempotencySection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('idempotency')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('lock_ttl')
                            ->defaultValue(60.0)
                            ->info('Renewable lock lifetime in seconds for asset mutation and target allocation.')
                            ->validate()
                                ->ifTrue(static fn (float $ttl): bool => $ttl <= 0.0)
                                ->thenInvalid('idempotency.lock_ttl must be greater than zero.')
                            ->end()
                        ->end()
                        ->integerNode('max_object_replays')
                            ->defaultValue(3)
                            ->min(1)
                            ->info('Maximum latest-state passes before a continuously changing object fails and must be retried.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addOperationJournalSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('operation_journal')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('recovery_after_seconds')
                            ->defaultValue(900)
                            ->min(1)
                        ->end()
                        ->integerNode('delivery_batch_size')
                            ->defaultValue(100)
                            ->min(1)
                            ->max(1000)
                        ->end()
                        ->integerNode('dispatch_deduplication_seconds')
                            ->defaultValue(3600)
                            ->min(1)
                        ->end()
                        ->integerNode('max_attempts')
                            ->defaultValue(5)
                            ->min(1)
                        ->end()
                        ->integerNode('base_retry_seconds')
                            ->defaultValue(30)
                            ->min(1)
                        ->end()
                        ->integerNode('max_retry_seconds')
                            ->defaultValue(3600)
                            ->min(1)
                        ->end()
                        ->integerNode('lease_seconds')
                            ->defaultValue(300)
                            ->min(1)
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $config): bool => $config['max_retry_seconds'] < $config['base_retry_seconds'])
                        ->thenInvalid('operation_journal.max_retry_seconds must be greater than or equal to base_retry_seconds.')
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
                        ->integerNode('retention_days')
                            ->defaultValue(90)
                            ->min(1)
                            ->info('Number of days to retain audit log entries before cleanup.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addOperationRunsSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('operation_runs')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('retention_days')->defaultValue(90)->min(1)->end()
                        ->integerNode('retention_batch_size')->defaultValue(500)->min(1)->max(1_000)->end()
                        ->integerNode('lease_seconds')
                            ->defaultValue(300)
                            ->min(1)
                            ->info('Durable liveness lease for an in-flight operation-run item. A worker renews it on each heartbeat; maintenance fails an item whose lease expired without one. Keep it above the Symfony lock TTL and the longest single asset save.')
                        ->end()
                        ->integerNode('stale_queued_warning_seconds')
                            ->defaultValue(86400)
                            ->min(60)
                            ->info('A run left awaiting dispatch (an unscheduled pimcore:maintenance relay) or queued (a lost broker message) longer than this is surfaced as a health warning. It is never auto-failed, since that would kill a legitimate backlog; an operator checks the scheduler/consumer and cancels/retries it.')
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

    protected function addNotificationsSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('notifications')
                    ->addDefaultsIfNotSet()
                    ->info('Opt-in alerts (default off). The built-in notifier sends a Pimcore in-app notification; tag oronts_asset_pilot.notifier to add email/Slack/webhook.')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Enable the failure-rate notification on bulk completion.')
                        ->end()
                        ->floatNode('failure_rate_threshold')
                            ->defaultValue(0.5)
                            ->min(0.0)
                            ->max(1.0)
                            ->info('Notify when a completed bulk run\'s failed/total ratio is at or above this (0..1).')
                        ->end()
                        ->arrayNode('recipient_user_ids')
                            ->integerPrototype()->end()
                            ->defaultValue([])
                            ->info('Pimcore backend user ids to notify in-app (an allow list; all are notified).')
                        ->end()
                        ->arrayNode('recipient_group_ids')
                            ->integerPrototype()->end()
                            ->defaultValue([])
                            ->info('Pimcore user-group (role) ids to notify in-app; all members of each are notified.')
                        ->end()
                        ->integerNode('sender_user_id')
                            ->defaultValue(0)
                            ->info('Pimcore user id the notification is sent from (0 = system).')
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
                    ->info('Opt-in guard: before deleting or moving an unused asset, scan Pimcore content tables for hard-coded paths.')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Enable the content-reference delete/move guard.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    protected function addDependencyProjectionSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('dependency_projection')
                    ->addDefaultsIfNotSet()
                    ->info('Indexed asset-reference projection used by destructive safety checks.')
                    ->children()
                        ->booleanNode('bootstrap_live_scan')
                            ->defaultTrue()
                            ->info('Allow a bounded live scan only before the first projection rebuild has started.')
                        ->end()
                        ->integerNode('bootstrap_max_sources')
                            ->defaultValue(50000)
                            ->min(1)
                            ->info('Maximum Pimcore elements inspected by the one-time live bootstrap fallback.')
                        ->end()
                        ->integerNode('rebuild_batch_size')
                            ->defaultValue(1000)
                            ->min(1)
                            ->max(10000)
                            ->info('Default maximum sources processed by one dependency projection rebuild command invocation.')
                        ->end()
                        ->integerNode('deletion_fence_lease_seconds')
                            ->defaultValue(900)
                            ->min(300)
                            ->info('Seconds a delete operation owns an asset deletion fence before the row is eligible for reaping. Must exceed the worst-case single-asset delete duration: a lease that expires mid-delete lets the reaper reclaim the fence while the delete is still running.')
                        ->end()
                        ->integerNode('deletion_fence_reap_batch_size')
                            ->defaultValue(1000)
                            ->min(1)
                            ->max(10000)
                            ->info('Maximum stale deletion-fence rows reclaimed by one maintenance reaper run.')
                        ->end()
                        ->integerNode('reconcile_stale_seconds')
                            ->defaultValue(300)
                            ->min(60)
                            ->info('Age at which a still-dirty dependency source (e.g. a deferred commit-fenced publication whose refresh message was lost) is re-dispatched for reconciliation, and a stale orphan pending row is cleared, by maintenance.')
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
