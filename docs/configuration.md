[← Documentation index](index.md) · [Project README](../README.md)

# Configuration

Create `config/packages/oronts_asset_pilot.yaml`:

```yaml
oronts_asset_pilot:
    enabled: true

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

    strategies:
        default: always

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

    logging:
        channel: asset_pilot
```

## Configuration Reference

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `true` | Global on/off switch |
| `rules` | `map` | `[]` | Named rule definitions (see below) |
| `strategies.default` | `enum` | `always` | Default strategy: `always`, `first_assignment`, `callback` |
| `naming.collision_pattern` | `enum` | `counter` | Filename collision resolution: `counter`, `timestamp`, `uuid` |
| `naming.slugify` | `bool` | `true` | Slugify filenames during organization |
| `async.enabled` | `bool` | `true` | Dispatch moves via Symfony Messenger |
| `async.batch_size` | `int` | `50` | Operations per batch message |
| `audit.enabled` | `bool` | `true` | Enable audit logging |
| `audit.retention_days` | `int` | `90` | Days to retain audit entries |
| `protection.exclude_folders` | `string[]` | `[]` | Folders excluded from organization (e.g., `["/Protected/"]`) |
| `protection.lock_property` | `string` | `asset_pilot_locked` | Custom property name used to lock assets |
| `logging.channel` | `string` | `asset_pilot` | Monolog channel name |

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
