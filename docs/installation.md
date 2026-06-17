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
- The `asset_pilot_audit_log` table with indexes on `asset_id`, `object_id`, `rule_name`, `status`, and `created_at`
- Three Pimcore permissions: `asset_pilot_view`, `asset_pilot_operate`, `asset_pilot_admin`

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

> The build depends on Pimcore's `@pimcore/studio-ui-bundle` npm package, which Pimcore ships as a
> tarball at `vendor/pimcore/studio-ui-bundle/public/build/studio-npm-package.tgz`. The `file:`
> reference in `assets/studio/package.json` is relative to the bundle's location, so it resolves only
> after `composer install` has placed `pimcore/studio-ui-bundle` in `vendor/`. If your install layout
> differs from the standard `vendor/oronts/asset-pilot-bundle`, adjust that relative path to point at
> the tarball.
