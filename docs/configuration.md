[← Documentation index](index.md) · [Project README](../README.md)

# Configuration

Create `config/packages/oronts_asset_pilot.yaml`:

```yaml
oronts_asset_pilot:
    enabled: true

    # Optional global allowlist. Empty means the save listener reacts to every class.
    allowed_classes: []

    # Optional: restrict localized-field scanning to these locales. Empty means all valid languages.
    locales: []

    rules:
        product_images:
            class: Product
            fields: [images, galleryImages]
            condition: 'object.getItemNumber() != null'
            target_path: '/Products/{{ object.getItemNumber() }}/Images'
            strategy: always
            priority: 100
            filters:
                types: [image]
                max_size: 52428800

        product_documents:
            class: Product
            fields: [datasheet, manual, brochure]
            target_path: '/Products/{{ object.getItemNumber() }}/Documents{{ locale ? "/" ~ locale : "" }}'
            strategy: always
            priority: 70
            # Optional: restrict this rule to specific locales of a localized field. Empty (default)
            # means all locales, and the rule then also applies to non-localized fields.
            locales: [en, de]

    naming:
        collision_pattern: counter
        slugify: true

    async:
        enabled: true
        batch_size: 50

    audit:
        enabled: true
        retention_days: 90

    protection:
        exclude_folders:
            - /Protected/
            - /Manual/
        lock_property: asset_pilot_locked

    confidence:
        recently_uploaded_days: 30
        probably_unused_days: 90

    quarantine:
        folder: /Quarantine
        grace_days: 30

    integrity:
        enabled: true
        skip_extensions: [svg]
        on_unrecoverable: report   # report | quarantine

    content_scan:
        enabled: false
        classes: []   # e.g. [Article, Page] — scan these classes' text fields before delete/move

    notifications:
        enabled: false
        failure_rate_threshold: 0.5   # 0..1
        recipient_user_ids: []        # Pimcore backend users to notify in-app (all are notified)
        recipient_group_ids: []       # and/or user groups (roles)
        sender_user_id: 0
```

## Configuration Reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `true` | Global on/off switch |
| `allowed_classes` | `string[]` | `[]` | Global allowlist of DataObject classes the save listener reacts to. Empty means all classes |
| `locales` | `string[]` | `[]` | Restrict localized-field scanning to these locales. Empty means all valid Pimcore languages |
| `rules` | `map` | `[]` | Named rule definitions (see below) |
| `naming.collision_pattern` | `enum` | `counter` | Filename collision resolution: `counter`, `timestamp`, `uuid` |
| `naming.slugify` | `bool` | `true` | Slugify filenames during organization |
| `async.enabled` | `bool` | `true` | Dispatch moves via Symfony Messenger |
| `async.batch_size` | `int` | `50` | Default operations per batch message, used as the default for the `--batch-size` CLI option and the bulk API `batchSize` |
| `audit.enabled` | `bool` | `true` | Enable audit logging |
| `audit.retention_days` | `int` | `90` | Days to retain audit entries |
| `protection.exclude_folders` | `string[]` | `[]` | Folders excluded from organization (e.g., `["/Protected/"]`) |
| `protection.lock_property` | `string` | `asset_pilot_locked` | Custom property name used to lock assets |
| `confidence.recently_uploaded_days` | `int` | `30` | Assets modified within this many days score `recently_uploaded` |
| `confidence.probably_unused_days` | `int` | `90` | Below this (and past `recently_uploaded_days`) scores `probably_unused`; older scores `definitely_unused` |
| `quarantine.folder` | `string` | `/Quarantine` | Folder quarantined assets are moved to instead of being deleted (reversible) |
| `quarantine.grace_days` | `int` | `30` | Days a quarantined asset is kept before the purge task may hard-delete it (if still unused) |
| `integrity.enabled` | `bool` | `true` | Enable broken-asset detection (the `findBroken` scan; explicit `--by-ids`/`?ids` checks ignore this) |
| `integrity.skip_extensions` | `string[]` | `[svg]` | Extensions skipped during the integrity scan (e.g. `svg`, which the renderer flags noisily) |
| `integrity.on_unrecoverable` | `enum` | `report` | A broken asset with no renderable version: `report` (log + heal-log row) or `quarantine` (also best-effort quarantine, only if still unused) |
| `content_scan.enabled` | `bool` | `false` | Before deleting/moving an unused asset, also scan rich-text/text fields for a hard-coded reference to its path (which the dependency table misses). Opt-in delete/move guard |
| `content_scan.classes` | `string[]` | `[]` | DataObject classes whose `wysiwyg`/`textarea`/`input` fields are scanned. Empty = the guard is inert |
| `notifications.enabled` | `bool` | `false` | Notify when a completed bulk run's failure rate crosses the threshold |
| `notifications.failure_rate_threshold` | `float` | `0.5` | Failed/total ratio (0..1) at or above which a notification is sent |
| `notifications.recipient_user_ids` | `int[]` | `[]` | Pimcore backend users notified in-app by the built-in notifier (all are notified; unknown ids are skipped) |
| `notifications.recipient_group_ids` | `int[]` | `[]` | Pimcore user groups (roles) whose members are notified in-app |
| `notifications.sender_user_id` | `int` | `0` | Pimcore user the in-app notification is sent from (`0` = system) |
| `duplicates.merge_strategy` | `string` | `quarantine` | Default disposition for a duplicate merge: a registered strategy name (`quarantine`, `delete`, `isolate`, or a custom tagged one). See [Extending](extending.md#duplicate-merge-strategies) |
| `cache.stats_ttl` | `int` | `60` | TTL (seconds) for the audit-stats cache (dashboard/metrics). `0` disables (always live) |
| `cache.unused_stats_ttl` | `int` | `300` | TTL (seconds) for the unused-asset storage-stats cache on the web endpoint. `0` disables |
| `zip.default_strategy` | `string` | `flat` | Default archive layout for downloads: `flat`, `folder`, `type`, or a custom `oronts_asset_pilot.zip_strategy` name |
| `zip.max_assets` | `int` | `1000` | Maximum number of assets packed into one download archive (min 1) |

## Rule Options

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `class` | `string` | yes | — | DataObject class name or `*` for wildcard |
| `fields` | `string[]` | no | `[]` | Field names to match. Empty = all asset fields |
| `locales` | `string[]` | no | `[]` | Locales to match for a localized asset field. Empty = any locale (and applies to non-localized fields too). A non-empty list never matches a non-localized field. Values are not validated against Pimcore's languages, so a typo silently matches nothing |
| `condition` | `string` | no | `null` | ExpressionLanguage condition (`object`, `asset`, `rule`, `locale` available) |
| `target_path` | `string` | yes | — | Twig path template |
| `strategy` | `enum` | no | `always` | `always`, `first_assignment`, `callback` |
| `callback` | `string` | no | `null` | Service ID (required when strategy is `callback`) |
| `priority` | `int` | no | `10` | Higher values match first |
| `enabled` | `bool` | no | `true` | Enable/disable individual rules |
| `filters.types` | `string[]` | no | `[]` | Asset types: `image`, `video`, `document`, etc. |
| `filters.min_size` | `int` | no | `null` | Minimum file size in bytes |
| `filters.max_size` | `int` | no | `null` | Maximum file size in bytes |
| `filters.extensions` | `string[]` | no | `[]` | Allowed file extensions |
| `options` | `map` | no | `{}` | Arbitrary per-rule data for custom filters/strategies |
| `actions` | `list` | no | `[]` | Post-move actions (`{type, ...}`); built-in `set_property`. See [Extending](extending.md#rule-actions-do-more-than-move) |

## Removed keys

The `strategies.default` and `logging.channel` keys were removed because nothing consumed them: the
per-rule `strategy` already defaults to `always`, and the bundle logs through the standard autowired
`LoggerInterface` (a dedicated Monolog channel is configured in the host application's `monolog.yaml`,
not here). If your config still sets either key, delete it, otherwise the container will reject it as
an unrecognized option.
