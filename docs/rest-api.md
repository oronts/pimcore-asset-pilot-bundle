[← Documentation index](index.md) · [Project README](../README.md)

# REST API

All endpoints are prefixed with `/pimcore-studio/api/asset-pilot`. Requires Pimcore Studio authentication. Permissions are enforced on every endpoint (see [Permissions](permissions.md)).

### Dashboard

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/dashboard` | View | Dashboard statistics |
| `GET` | `/dashboard/class-stats` | View | Per-class breakdown |
| `GET` | `/permissions` | — | Current user's permission set |

### Operations

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `POST` | `/organize` | Operate | Organize a single object |
| `POST` | `/organize/preview` | View | Dry-run preview |
| `POST` | `/organize/explain` | View | Detailed rule evaluation per asset |
| `POST` | `/organize/bulk` | Operate | Bulk organize by class or IDs |
| `POST` | `/operations/bulk-preview` | View | Paginated bulk preview |
| `GET` | `/operations/status` | View | Operation statistics |

#### Organize request body

```json
{
    "objectId": 42,
    "dryRun": false,
    "async": true
}
```

#### Bulk organize request body

```json
{
    "className": "Product",
    "async": true,
    "batchSize": 50
}
```

Or with explicit IDs:

```json
{
    "objectIds": [1, 2, 3, 4, 5],
    "async": true,
    "batchSize": 50
}
```

#### Explain response

```json
{
    "objectId": 42,
    "operations": [{
        "assetId": 456,
        "sourcePath": "/uploads/photo.jpg",
        "targetPath": "/Products/ART-123/Images/photo.jpg",
        "ruleName": "product_images",
        "status": "completed"
    }],
    "evaluations": [{
        "assetId": 456,
        "assetPath": "/uploads/photo.jpg",
        "fieldName": "images",
        "locale": null,
        "ruleName": "product_images",
        "matched": true,
        "rejectionReason": null,
        "conditionExpression": "object.getItemNumber() != null",
        "conditionResult": true,
        "conditionError": null,
        "filterDetails": null,
        "resolvedPath": "/Products/ART-123/Images",
        "priority": 100,
        "enabled": true
    }, {
        "assetId": 456,
        "assetPath": "/uploads/photo.jpg",
        "fieldName": "images",
        "locale": null,
        "ruleName": "product_documents",
        "matched": false,
        "rejectionReason": "filter_rejected",
        "conditionExpression": null,
        "conditionResult": true,
        "conditionError": null,
        "filterDetails": "type mismatch: image not in [document]",
        "resolvedPath": null,
        "priority": 70,
        "enabled": true
    }]
}
```

### Rules

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/rules` | View | List all configured rules |
| `GET` | `/rules/{name}` | View | Rule details with statistics |
| `GET` | `/rules/{name}/preview?objectId=42` | View | Preview rule against an object |

### Asset Management

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/assets/search` | View | Search assets (params: `q`, `type`, `folder`, `objectId`, `page`, `limit`, `sort`, `order`) |
| `GET` | `/assets/by-object/{objectId}` | View | Assets linked to a DataObject via dependencies (params: `page`, `limit`, `type`) |
| `GET` | `/assets/tags` | View | List all available Pimcore tags |
| `GET` | `/assets/{id}/tags` | View | Get tags for a specific asset |
| `POST` | `/assets/{id}/lock` | Operate | Lock asset from organization |
| `DELETE` | `/assets/{id}/lock` | Operate | Unlock asset |
| `POST` | `/assets/bulk-tag` | Operate | Bulk assign tags to assets |
| `POST` | `/assets/bulk-property` | Operate | Bulk set custom properties |

#### Search parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `q` | `string` | Search by filename or path (LIKE match) |
| `type` | `string` | Filter by asset type: `image`, `document`, `video`, `audio`, `text`, `archive` |
| `folder` | `string` | Filter by folder path (e.g., `/Products/`) |
| `objectId` | `int` | Filter to assets referenced by a specific DataObject (via dependencies table) |
| `page` | `int` | Page number (default: 1) |
| `limit` | `int` | Items per page (default: 50, max: 200) |
| `sort` | `string` | Sort field: `id`, `filename`, `type`, `file_size`, `modified_at` |
| `order` | `string` | Sort order: `asc` or `desc` |

#### Bulk tag request body

```json
{
    "assetIds": [1, 2, 3],
    "tagIds": [10, 20],
    "replace": false
}
```

Set `replace: true` to remove all existing tags before assigning new ones.

#### Bulk property request body

```json
{
    "assetIds": [1, 2, 3],
    "name": "department",
    "type": "text",
    "data": "Marketing"
}
```

Supported types: `text`, `bool`, `select`.

### Unused Assets

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/unused-assets` | View | List unused assets with confidence scoring |
| `GET` | `/unused-assets/stats` | View | Unused asset statistics by type |
| `POST` | `/unused-assets/bulk-delete` | Operate | Delete unused assets |
| `POST` | `/unused-assets/bulk-move` | Operate | Move unused assets to folder |

#### Unused assets parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | `string` | Filter by asset type |
| `extension` | `string` | Comma-separated extensions (e.g., `pdf,png,jpg`) |
| `before` | `string` | Modified before date (ISO format) |
| `after` | `string` | Modified after date (ISO format) |
| `folder` | `string` | Filter by folder path |
| `confidence` | `string` | Filter by confidence: `definitely_unused`, `probably_unused`, `recently_uploaded`, `historically_used`, `protected` |
| `page` | `int` | Page number (default: 1) |
| `limit` | `int` | Items per page (default: 50, max: 200) |
| `sort` | `string` | Sort field |
| `order` | `string` | Sort order: `asc` or `desc` |

#### Confidence levels

| Level | Criteria | Recommended Action |
|-------|----------|--------------------|
| `definitely_unused` | No references, last modified >90 days ago | Safe to delete |
| `probably_unused` | No references, last modified 30-90 days ago | Review before deleting |
| `recently_uploaded` | No references, last modified <30 days ago | Wait — may be in use soon |
| `historically_used` | No references, but has audit history of past moves | Investigate before deleting |
| `protected` | Has `asset_pilot_locked` property | Excluded from cleanup |

The 30/90-day cutoffs are defaults; tune them via `confidence.recently_uploaded_days` and
`confidence.probably_unused_days` (see [Configuration](configuration.md)).

### Audit Log

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/audit` | View | Paginated audit entries (params: `page`, `limit`, `class`, `status`, `ruleName`, `sort`, `order`) |
| `GET` | `/audit/stats` | View | Audit statistics |
| `GET` | `/audit/export` | View | Export as CSV (params: `class`, `status`, `ruleName`) |
| `GET` | `/audit/by-rule/{ruleName}/assets` | View | Distinct assets moved by a rule (params: `page`, `limit`, `since`, `class`) |
| `POST` | `/audit/{id}/revert` | Admin | Revert a completed operation |

#### Assets by rule parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `since` | `string` | Only include moves after this date (ISO format, e.g., `2026-02-01`) |
| `class` | `string` | Filter by object class name |
| `page` | `int` | Page number (default: 1) |
| `limit` | `int` | Items per page (default: 50) |
