[← Documentation index](index.md) · [Project README](../README.md)

# Installation and operations

## Requirements

- PHP 8.4
- Pimcore 12.3.11 or newer on the 12.3 supported line
- Pimcore Studio Backend 2025.4.7 and Studio UI 2025.4.8 or newer on the 2025.4 LTS line
- `ext-zip`; `ext-imagick` is optional and enables image integrity verification
- a shared Symfony Lock store and shared `cache.app` for multi-process deployments
- two supervised Messenger consumers for durable operation delivery and maintenance; organization
  can run synchronously, but these consumers are still required

## Fresh installation

Asset Pilot is a Pimcore Studio bundle. On a project where Studio is already installed, continue
with the Asset Pilot steps below. On a bare Pimcore Classic skeleton, first complete the official
Pimcore Studio installation, including its security provider and firewall configuration, and
register these bundles before Asset Pilot:

```php
return [
    Pimcore\Bundle\GenericExecutionEngineBundle\PimcoreGenericExecutionEngineBundle::class => ['all' => true],
    Pimcore\Bundle\GenericDataIndexBundle\PimcoreGenericDataIndexBundle::class => ['all' => true],
    Pimcore\Bundle\StudioBackendBundle\PimcoreStudioBackendBundle::class => ['all' => true],
    Pimcore\Bundle\StudioUiBundle\PimcoreStudioUiBundle::class => ['all' => true],
    Oronts\AssetPilotBundle\OrontsAssetPilotBundle::class => ['all' => true],
];
```

For a new Studio installation, install the prerequisites in dependency order. Skip bundles already
reported as installed by `pimcore:bundle:list`:

```bash
bin/console pimcore:bundle:install PimcoreGenericExecutionEngineBundle
bin/console pimcore:bundle:install PimcoreGenericDataIndexBundle
bin/console pimcore:bundle:install PimcoreStudioBackendBundle
bin/console pimcore:bundle:install PimcoreStudioUiBundle
```

Use Pimcore Studio's documented security configuration rather than copying a firewall from an
unrelated project. Asset Pilot's API is protected by the `pimcore_studio` firewall and will not be
usable until Studio authentication is working.

These docs describe the 2.0 line. 2.0 is the next release and is not yet published: `^2.0` will not
resolve until `v2.0.0` is tagged on Packagist. Install the latest published stable, the 1.1 line:

```bash
composer require oronts/asset-pilot-bundle:^1.1
```

Once 2.0 is published, install it with `composer require oronts/asset-pilot-bundle:^2.0`. To evaluate
the unreleased 2.0 line before then, require it from a VCS repository entry pointing at the release
branch (`"oronts/asset-pilot-bundle": "dev-feature/v1.2.0"`).

If Studio is already registered, add only Asset Pilot to `config/bundles.php`:

```php
return [
    Oronts\AssetPilotBundle\OrontsAssetPilotBundle::class => ['all' => true],
];
```

Install the bundle-owned tables and permission definitions:

```bash
bin/console pimcore:bundle:install OrontsAssetPilotBundle
bin/console assets:install
bin/console pimcore:cache:clear
```

The installer verifies that every permission was persisted and invalidates Studio's permission-key
cache. The explicit Pimcore cache clear remains a safe deployment boundary after bundle and role
changes.

The Composer package contains a prebuilt, versioned Studio Module Federation remote under
`public/studio/build`. Consumers do not need Node, npm, or a writable `vendor` directory. The entry
point provider reads `active.json` and loads exactly one validated generation. Source builds are only
for contributors and custom distributions.

## Messenger

Configure a durable transport with a retry strategy and a dedicated failure transport. Scope
`failure_transport` to the `asset_pilot` transport so only Asset Pilot's own exhausted messages are
diverted. Do not set the global `framework.messenger.failure_transport`: that would redirect the
failure destination for every unrelated transport in the host application. Bundle installation must
leave the host's global failure policy untouched. This transport-scoped form is the same pattern
Pimcore's own bundles use and the one the reference test application ships.

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            asset_pilot:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%/asset_pilot'
                failure_transport: asset_pilot_failed
                retry_strategy:
                    max_retries: 3
                    delay: 2000
                    multiplier: 3
                    max_delay: 30000
            asset_pilot_failed:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%/asset_pilot_failed'

        routing:
            'Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage': asset_pilot
            'Oronts\AssetPilotBundle\Message\BulkOrganizeMessage': asset_pilot
            'Oronts\AssetPilotBundle\Message\OperationDeliveryMessage': asset_pilot
            'Oronts\AssetPilotBundle\Message\DependencyProjectionRefreshMessage': asset_pilot
```

Run both consumers under Supervisor, systemd, Kubernetes, or an equivalent process manager:

```bash
bin/console messenger:consume asset_pilot --time-limit=3600 --memory-limit=256M
bin/console messenger:consume pimcore_maintenance --time-limit=3600 --memory-limit=256M
```

Also schedule Pimcore maintenance on a recurring interval. `pimcore:maintenance` is a command that must
run from cron or a timer, not a daemon. It dispatches one message per maintenance task onto the
`pimcore_maintenance` transport, so the `pimcore_maintenance` consumer above only executes tasks that
this scheduler creates. Without it, an object save records a committed `pending_dispatch` organize run
that is never published, and automatic organization silently does nothing:

```cron
* * * * * cd /var/www/html && flock -n /tmp/pimcore-maintenance.lock bin/console pimcore:maintenance
```

Use the interval your deployment needs and prevent overlapping runs (the `flock` above). To summarize the
two roles: the `pimcore:maintenance` scheduler DISPATCHES the maintenance-task messages; the
`pimcore_maintenance` consumer EXECUTES them.

The first consumer performs queued organization and actor-aware durable observer delivery. The
second executes Pimcore maintenance tasks, including the organize dispatch relay (publishing committed
`pending_dispatch` runs), durable-delivery outbox polling, audit and
operation-run retention, operation-run item-lease reconciliation (failing items whose durable
liveness lease expired and finalizing interrupted runs), quarantine purge, and storage snapshots. Configure graceful termination
longer than the largest expected operation, restart consumers after deploys, alert on failed messages
and queue age, and inspect receiver counts with
`bin/console messenger:stats asset_pilot asset_pilot_failed`. Where the receiver supports it, inspect
failed messages with `bin/console messenger:failed:show --transport=asset_pilot_failed`; otherwise
use the broker's management tooling and structured application logs. Each worker records a shared-cache
heartbeat. `async.worker_heartbeat_max_age` controls when a missing or stale required consumer makes
the bundle health result critical. Synchronous organization still requires the `asset_pilot`
consumer because committed operations use that transport for durable rule actions and operation
events. The scheduled `pimcore:maintenance` pass relays committed `pending_dispatch` organize runs and
retries the durable-delivery outbox, so a rolled-back save leaves no message and broker outages or lost
dispatches are retried.

Retryable database, shared-cache, lock-storage, and asset-storage failures are propagated to
Messenger and use the configured retry strategy. Permission denials, rule rejections, stale inputs,
and other domain outcomes are recorded as explicit skipped or failed results and are acknowledged.
If the receiver names differ, set `async.transport` and `async.failure_transport` to the exact
Messenger transport names so readiness checks inspect the same queues used by routing.

After installing or upgrading, bootstrap the dependency projection in bounded invocations before
enabling destructive cleanup. The cursor is durable, so the same command resumes after deploys or
worker restarts:

```bash
bin/console asset-pilot:rebuild-dependency-projection
bin/console asset-pilot:health
```

Rerun the rebuild command until it reports `ready`. Element save and delete events then maintain the
projection synchronously; the routed refresh message repairs transient failures without depending on
Pimcore's own dependency message being synchronous.

Durable observer retry, lease, batch, and stale-recovery behavior is configured under
`operation_journal`; see [Configuration](configuration.md#configuration-reference). A dead observer
delivery or an operation requiring manual recovery makes readiness critical. Stale unfinished
operations and overdue deliveries make it warning until recovered or delivered.

## Shared locking

```yaml
# config/packages/lock.yaml
framework:
    lock: '%env(LOCK_DSN)%'
```

Use Redis or another shared lock store in production. `cache.app` must also be shared across web and
worker processes. A filesystem, array, APCu, or process-local cache cannot provide cross-worker
idempotency.

The renewable mutation lease is configurable:

```yaml
oronts_asset_pilot:
    idempotency:
        lock_ttl: 60
```

Set the TTL above the normal remote-storage latency. `operation_runs.lease_seconds` (default 300) is
the durable liveness lease for an in-flight operation-run item; keep it above both
`idempotency.lock_ttl` and the longest single-asset save, otherwise raising `lock_ttl` past the lease
can trigger premature item-lease failures. The health endpoint intentionally reports a
warning when cross-process cache visibility cannot be proven, the dependency projection is being
built or contains dirty sources, or an operation run has stayed awaiting dispatch or queued past
`operation_runs.stale_queued_warning_seconds` (default 86400s), which signals an unscheduled
`pimcore:maintenance` relay or a probably-lost broker
message that maintenance never auto-fails (an operator cancels and retries it, or verifies the
consumers). It is critical when dependency tracking is disabled, the projection
rebuild failed, or either required worker heartbeat is missing or stale.

## Studio API prefix and static asset base

API routes inherit `pimcore_studio_backend.url_prefix`, including custom Studio prefixes. Prebuilt
static assets use Pimcore's default root-relative `/bundles/orontsassetpilot` path. A custom source
build can set `ASSET_PILOT_ASSET_BASE` to a validated subpath or absolute CDN base before `npm run
build`.

## Upgrade to 2.0

This procedure applies once `v2.0.0` is tagged and published on Packagist; the `^2.0` constraint
below cannot resolve before then.

Before changing packages:

1. Pause automatic producers or enable a maintenance window.
2. Drain `asset_pilot` and `pimcore_maintenance`, then stop both supervised consumers.
3. Back up every `asset_pilot_*` table and record the quarantine folder contents.
4. Update the package and run migrations:

```bash
composer require oronts/asset-pilot-bundle:^2.0 --with-all-dependencies
bin/console doctrine:migrations:migrate --no-interaction
bin/console assets:install
bin/console pimcore:cache:clear
bin/console asset-pilot:health
```

5. Restart both consumers and monitor queue age, failures, and the health endpoint.

The 2.0 migration repairs upgrades from the released v1.0 audit-only schema and partial v1.1
schemas, including missing columns, primary keys, and indexes. It also backfills the durable
`asset_pilot_first_assignment` bool property for assets with a retained `completed` or
`completed_with_observer_error` audit row. Both record a committed move. History already pruned
cannot be reconstructed automatically; review those assets before enabling
`first_assignment` rules.

See [UPGRADING.md](../UPGRADING.md) for compatibility and behavior changes.

## Safe uninstall

Uninstall is destructive: it drops all owned tables and removes all bundle permission
definitions. Before uninstalling, stop producers, drain and stop both consumers, back up the tables,
restore any assets that must leave quarantine, and preserve audit/export evidence.

```bash
bin/console pimcore:bundle:uninstall OrontsAssetPilotBundle
bin/console pimcore:cache:clear
```

Remove Messenger routing and supervisors only after the queues are empty. Removing the bundle does
not automatically restore quarantined assets or recreate deleted audit history.
