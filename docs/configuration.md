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

## Rule Options

| Key | Type | Required | Default | Description |
|-----|------|----------|---------|-------------|
| `class` | `string` | yes | — | DataObject class name or `*` for wildcard |
| `fields` | `string[]` | no | `[]` | Field names to match. Empty = all asset fields |
| `condition` | `string` | no | `null` | ExpressionLanguage condition |
| `target_path` | `string` | yes | — | Twig path template |
| `strategy` | `enum` | no | `always` | `always`, `first_assignment`, `callback` |
| `callback` | `string` | no | `null` | Service ID (required when strategy is `callback`) |
| `priority` | `int` | no | `10` | Higher values match first |
| `enabled` | `bool` | no | `true` | Enable/disable individual rules |
| `filters.types` | `string[]` | no | `[]` | Asset types: `image`, `video`, `document`, etc. |
| `filters.min_size` | `int` | no | `null` | Minimum file size in bytes |
| `filters.max_size` | `int` | no | `null` | Maximum file size in bytes |
| `filters.extensions` | `string[]` | no | `[]` | Allowed file extensions |

## Removed keys

The `strategies.default` and `logging.channel` keys were removed because nothing consumed them: the
per-rule `strategy` already defaults to `always`, and the bundle logs through the standard autowired
`LoggerInterface` (a dedicated Monolog channel is configured in the host application's `monolog.yaml`,
not here). If your config still sets either key, delete it, otherwise the container will reject it as
an unrecognized option.
