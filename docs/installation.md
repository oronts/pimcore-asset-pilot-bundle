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

```bash
bin/console assets:install
bin/console cache:clear
```
