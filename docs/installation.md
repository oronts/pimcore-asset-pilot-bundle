[← Documentation index](index.md) · [Project README](../README.md)

# Installation

### 1. Require the package

```bash
composer require oronts/asset-pilot-bundle
```

### 2. Enable the bundle

Add to `config/bundles.php`:

```php
return [
    // ...
    Oronts\AssetPilotBundle\OrontsAssetPilotBundle::class => ['all' => true],
];
```

### 3. Install the database table and permissions

```bash
bin/console pimcore:bundle:install OrontsAssetPilotBundle
```

This creates:
- The owned tables: `asset_pilot_audit_log`, `asset_pilot_quarantine`, `asset_pilot_integrity_log`,
  `asset_pilot_checksum`, and `asset_pilot_storage_snapshot` (with their indexes)
- Three Pimcore permissions: `asset_pilot_view`, `asset_pilot_operate`, `asset_pilot_admin`

Then clear the Pimcore data cache so Studio recognises the new permissions:

```bash
bin/console pimcore:cache:clear
```

Studio caches the set of known permission keys (`USER_PERMISSIONS`); until this runs, the
`asset_pilot_*` permissions are unknown and every endpoint returns 403. Symfony's `cache:clear` does
not clear the Pimcore data cache, so use the `pimcore:` command above.

The installer owns the schema and is **idempotent** (it creates a table when absent and otherwise adds
only missing columns/indexes, never dropping data). A fresh install creates the full current schema and
marks the bundle's Doctrine migrations as already applied. Existing installs pick up later schema
deltas through the bundle's Doctrine migrations (it ships migrations under `src/Migrations`):

```bash
bin/console doctrine:migrations:migrate
```

Re-running the installer is also safe and brings an existing schema up to date:

```bash
bin/console pimcore:bundle:install OrontsAssetPilotBundle
```

### 4. Configure Messenger transport

Add the transport and routing to `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            asset_pilot:
                dsn: '%messenger.dsn%/asset_pilot'
                retry_strategy:
                    max_retries: 3
                    delay: 2000
                    multiplier: 3
                    max_delay: 30000

        routing:
            'Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage': asset_pilot
            'Oronts\AssetPilotBundle\Message\BulkOrganizeMessage': asset_pilot
```

### 5. Configure Symfony Lock (required for message deduplication)

```yaml
# config/packages/lock.yaml
framework:
    lock: 'redis://%env(REDIS_HOST)%'
```

Enabling `framework.lock` is what makes the async deduplication work: Symfony registers the
Messenger `DeduplicateMiddleware` on the default bus automatically once lock is enabled, and that
middleware is what gives the `DeduplicateStamp` (used by the save listeners) its effect. Without a
lock configured the stamp is inert and duplicate organize messages are not collapsed. This requires
`symfony/messenger` and `symfony/lock` `^7.3` (both are declared by the bundle).

### 6. Build the Studio UI assets

The Studio UI is a Module Federation remote that ships as source under `assets/studio` and is not
prebuilt in the package, so build it once after install (and after each bundle upgrade). The build
writes `public/studio/build/<id>/entrypoints.json`, which `WebpackEntryPointProvider` discovers.

```bash
npm --prefix assets/studio ci
npm --prefix assets/studio run build

bin/console assets:install
bin/console cache:clear
```

`public/studio/build/` is generated output (gitignored). In CI/CD, run the two `npm` commands as a
build step before deploying. Until the build runs, the backend (rules engine, CLI, events, audit,
REST API) works, but the Studio dashboard tabs do not load.

> The Studio build depends on Pimcore's `@pimcore/studio-ui-bundle` package, pinned to a version in
> `assets/studio/package.json`. Pimcore ships its Studio assets as a tarball (`studio-npm-package.tgz`),
> which `assets/studio/rsbuild.config.ts` preserves under the bundle's own `public/studio/build/` across
> rebuilds. Run `npm ci && npm run build` from `assets/studio`, and keep the pinned package version
> aligned with the `pimcore/studio-ui-bundle` version installed in your project.
