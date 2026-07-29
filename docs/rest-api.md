[← Documentation index](index.md) · [Project README](../README.md)

# REST API

All endpoints use `%pimcore_studio_backend.url_prefix%/asset-pilot`; the default is
`/pimcore-studio/api/asset-pilot`. Requests require Pimcore Studio authentication. Permissions are
enforced on every endpoint (see [Permissions](permissions.md)).

### Response conventions

Every resource timestamp in a JSON response is an RFC 3339 (ISO 8601) string in UTC with an
explicit `+00:00` offset, for example `2026-07-15T10:00:00+00:00`. A stored value that is absent
serializes as `null`. Owned-table timestamps are serialized through a single formatting seam
(`ApiDateFormatter`); Pimcore-native asset timestamps (the asset `created_at` and `modified_at`
fields) are rendered as RFC 3339 UTC from their stored Unix time. The format is uniform across the
dashboard, audit, quarantine, storage trend, integrity history, asset, unused-asset, and operation
and delivery endpoints. Three things are intentionally outside this guarantee: the `before` and
`after` filter query parameters accept plain ISO 8601 dates; CSV exports stream the stored value
directly; and the `/health` response `details` bag carries diagnostic, UTC-by-construction
timestamps for operators rather than the RFC 3339 resource form.

The authorized list endpoints (asset search, unused assets, quarantine, integrity history, and
duplicates) page through a bounded scan that runs the native `isAllowed('view')` check on every
candidate row. When that scan reaches its budget before it can prove where the listing ends, the
response carries `truncated: true` next to `hasMore`. Read `truncated: true` as "results are
limited, narrow the filters to reach the rest," never as a real end of the listing; `hasMore: false`
on its own still marks a genuine end. The System actor bypasses the per-row check, reads an exact
SQL total, and never returns `truncated: true`. The matching CSV exports stream to a row ceiling and,
when that ceiling cuts the stream short, append a final `TRUNCATED: ...` marker row: a streamed
download cannot carry a trailing header, so the notice travels in band as the last row. The audit
export (unbounded keyset cursor) and the empty-folders listing (next-row probe) have no such ceiling
and never signal truncation.

### Dashboard

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/dashboard` | View | Dashboard statistics |
| `GET` | `/dashboard/class-stats` | View | Per-class breakdown |
| `GET` | `/health` | View | Health checks + overall status (`{status, checks[]}`) |
| `GET` | `/health/readiness` | View | Lightweight readiness status; returns `503` when any health check is critical |
| `GET` | `/metrics` | View | Operation metrics (`{operations, total, moveTotal, failureRate, durationMs}`; `failureRate` = failed / `moveTotal`, which excludes nonterminal operations) |
| `GET` | `/duplicates` | View | Byte-identical asset groups from the content-hash index (`?page`, `?limit`, `?minCopies` (default 2), `?type`). Returns `{items[], total, page, limit, hasMore, truncated}` — `total` is `null` for interactive (non-System) callers, so use `hasMore` to detect further pages and `truncated` to detect a scan-budget cutoff. Read-only — build the index with `asset-pilot:find-duplicates --scan` |
| `GET` | `/duplicates/strategies` | View | Available merge-disposition strategies + the configured default (`{strategies[], default}`) |
| `GET` | `/duplicates/export` | View | Stream the full duplicate report as CSV (`?minCopies`, `?type`) |
| `POST` | `/duplicates/merge` | Admin | Preview/apply a merge with `{checksum, canonicalId?, strategy?, dryRun?, planToken?}`, or resume with `{runId}`. Apply uses a signed plan and returns durable run state. Errors: `400` (bad checksum/token), `403` (not permitted), `404` (no group / unknown run), `409` (stale plan or a retryable finalization race). |
| `GET` | `/folders/empty` | View | Empty asset folders (`?folder`, `?page`, `?limit`). Returns `{items[], page, limit, hasMore}` (no total; use `hasMore` for pagination) |
| `GET` | `/storage/trends` | Admin | Global unused-storage series from the snapshots (`?type`, `?limit` max 365). Returns `{type, items[]}` — build snapshots with `asset-pilot:capture-storage-snapshot` |
| `POST` | `/folders/empty/delete` | Operate | Preview or delete empty folders through a signed apply plan (`{ids[], dryRun?, planToken?}`, 1..200 ids; each re-verified childless + permission-checked). Preview returns `{deleted: 0, eligible, skipped, failed, errors, dryRun, planToken}`; apply returns `{deleted, skipped, failed, errors, dryRun, planToken: null}` |
| `GET` | `/integrity` | View | Broken assets. Bounded non-folder scan (`?folder`, `?type`, `?extension`, `?page`, `?limit`) or check specific ids (`?ids=1,2,3`, max 50). Returns `{items[], scanned, broken, page, limit, hasNext}` |
| `POST` | `/integrity/heal` | Operate | Preview or roll broken assets back through a signed apply plan (`{ids[], dryRun?, planToken?}`, max 50 ids). Returns `{dryRun, planToken, results[]}` |
| `GET` | `/integrity/history` | Admin | Paginated completed reversible-heal records (`?page`, `?limit`, max 50). Results are asset-workspace scoped and include current `eligible`, `eligibilityReason`, and `reason` values. The lightweight read probe checks view/publish access, protection, exclusion rules, and pre-heal version availability without taking mutation locks or reading binaries. The undo endpoint repeats those checks under lock and verifies the live binary, so it can still reject a row whose state changed after listing. |
| `POST` | `/integrity/undo` | Admin | Reverse the most recent heal of one asset (`{assetId}`); 404 when there is no reversible heal |

#### Empty-folder delete preview and apply

Deleting empty folders is a mandatory two-step reviewed-plan operation; a single request can never
both preview and delete. Both steps post to `/folders/empty/delete` and carry the same `ids` array (1
to 200 folder ids: an empty selection or one above the ceiling returns `400`). The caller needs the
Operate permission, and every folder is additionally checked for its native `delete` permission.

Preview first with `dryRun: true`:

```json
{
    "ids": [1024, 1025],
    "dryRun": true
}
```

The preview classifies each folder without deleting anything and returns a signed, single-use
`planToken`:

```json
{
    "deleted": 0,
    "eligible": 1,
    "skipped": 1,
    "failed": 0,
    "errors": {},
    "dryRun": true,
    "planToken": "v1..."
}
```

`deleted` is always `0` on a preview. `eligible` counts folders that would be deleted. `skipped`
counts folders that are the asset root, no longer exist, or already regained children since the
listing. `failed` counts folders the actor may not delete; each carries a message in the `errors`
map keyed by folder id, for example `{"1024": "Not permitted to delete this folder"}`. If a folder
changes while the preview is being built, no token is issued and the response is `409`.

Apply the identical id set with `dryRun: false` and the returned token:

```json
{
    "ids": [1024, 1025],
    "dryRun": false,
    "planToken": "v1..."
}
```

Apply locks each folder, re-verifies it is still childless and still deletable, then reports the
final counts. The apply response omits `eligible` and returns `planToken: null`:

```json
{
    "deleted": 1,
    "skipped": 1,
    "failed": 0,
    "errors": {},
    "dryRun": false,
    "planToken": null
}
```

The token binds the actor, the sorted folder ids, the plan configuration, and each folder's
fingerprint (existence, child state, modification time, and path). It is single-use and expires 300
seconds (5 minutes, the `ApplyPlanService` default) after the preview. Applying without a token
returns `400` (`A planToken from a fresh dry-run preview is required.`); a malformed token also
returns `400`. An expired token, a token already claimed, or a folder whose fingerprint changed or
that is locked by another operation after preview returns `409` and must be previewed again.

#### Integrity heal preview and apply

HTTP healing always starts with a dry-run. The preview sorts the requested asset IDs and returns the
exact per-asset heal result with a signed, single-use token:

```json
{
    "ids": [1025, 1024],
    "dryRun": true
}
```

```json
{
    "dryRun": true,
    "planToken": "v1...",
    "results": [
        {
            "assetId": 1024,
            "outcome": "healed",
            "toVersion": 87,
            "checker": "image",
            "reason": null,
            "observerWarnings": []
        }
    ]
}
```

Apply the same ID set with the returned token:

```json
{
    "ids": [1024, 1025],
    "planToken": "v1..."
}
```

The token binds the actor, sorted IDs, effective integrity and side-effect configuration, selected
checker, exact preview outcome, live path, modification time, checksum, protection state, and stored
version descriptors. A token is not issued if an asset changes while preview is built. Apply locks
all targets in sorted order, force-reloads and validates the whole batch before the first restore,
then checks each asset again immediately before its restore. Missing or malformed tokens return
`400`; preview races, changed targets, expired tokens, and token reuse return `409`. The CLI also
previews by default and requires its own System-actor plan token with `--apply`; HTTP and CLI tokens
are deliberately not interchangeable.

#### Duplicate merge preview, apply, and resume

Preview the exact checksum group, canonical asset, and strategy without changing references:

```json
{
    "checksum": "content-hash",
    "canonicalId": 123,
    "strategy": "quarantine",
    "dryRun": true
}
```

The response contains `dryRun: true`, a signed single-use `planToken`, the selected canonical asset,
and the planned `dispositions`. Apply the identical selection with the token:

```json
{
    "checksum": "content-hash",
    "canonicalId": 123,
    "strategy": "quarantine",
    "planToken": "v1..."
}
```

Apply revalidates the group and fingerprints, then returns `runId`, `status`, `statusUrl`, and the
current dispositions. Each copy persists its reference-repoint and disposition phase in that run.
If execution is interrupted, resume the actor-scoped run without a new plan:

```json
{
    "runId": "0123456789abcdef0123456789abcdef"
}
```

The service continues from the persisted phase and does not repeat a committed phase. Copies whose
references cannot be fully repointed are blocked and remain untouched.

A missing or empty `checksum` returns `400`, and a checksum with no live duplicate group (fewer than
two live assets) returns `404`. An actor who may not merge the group returns `403`. On apply, a missing
`planToken` returns `400`, a malformed token returns `400`, and a stale or already-used plan returns
`409`. Both apply and resume also return `409` with the message
`The duplicate merge run could not be finalized; retry to complete it.` when the copies were repointed
and disposed but the run parent could not be finalized (a lost merge lease or finalization race). That
case is retryable and self-describing: the `409` body carries the run's `runId` and its `statusUrl`, so
POST that same `{runId}` back to `/duplicates/merge` to complete the finalization. A resume for an
unknown run returns `404`.

### Operations

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `POST` | `/organize` | Operate | Preview or organize one object with a signed apply plan |
| `POST` | `/organize/explain` | View | Detailed rule evaluation per asset |
| `POST` | `/organize/bulk` | Operate | Preview or bulk organize by class or IDs with a signed apply plan |
| `POST` | `/operations/bulk-preview` | View | Paginated object-selector browser; it does not create an apply plan |
| `POST` | `/operations/replay` | Operate | Preview and re-run failed objects through a signed apply plan (`{since?, rule?, class?, limit?, dryRun?, planToken?, async?}`) |
| `POST` | `/operations/reorganize` | Operate | Preview and re-organize owners of assets in a folder through a signed apply plan (`{folder, limit?, dryRun?, planToken?, async?}`) |
| `POST` | `/operations/recovery` | Admin | Preview stale move/revert journal classifications or apply the exact signed review (`{limit?, apply?, planToken?}`) |
| `POST` | `/operations/deliveries/retry` | Admin | Preview dead durable deliveries or atomically requeue the exact signed review (`{limit?, apply?, planToken?}`) |
| `GET` | `/operations/status` | View | Operation statistics |
| `GET` | `/operations/runs` | View | List the current actor's recent operation runs (`?limit`, default 20, max 100). Returns `{items[], limit}` without per-run item details |
| `GET` | `/operations/runs/{id}` | View | Read an actor-scoped queued or completed operation run |
| `POST` | `/operations/runs/{id}/cancel` | Operate | Cancel a `pending_dispatch`, queued, or running run; pending and queued runs become `cancelled` immediately (a running run becomes `cancel_requested`). A listener-created automatic organize run starts in `pending_dispatch` until the maintenance relay publishes it |
| `POST` | `/operations/runs/{id}/retry` | Operate | Retry blocked, failed, or cancelled items as a new actor-scoped run; object work retains immutable-plan fingerprints and duplicate merges resume their persisted phases. Skipped items are terminal and are not retried. |

#### Organize preview and apply

Every organize mutation starts with a dry-run. The preview returns the operations and a signed,
single-use `planToken`:

```json
{
    "objectId": 42,
    "dryRun": true
}
```

```json
{
    "dryRun": true,
    "planToken": "v1...",
    "operations": [{
        "assetId": 456,
        "sourcePath": "/uploads/photo.jpg",
        "targetPath": "/Products/ART-123/Images/photo.jpg",
        "ruleName": "product_images",
        "objectId": 42,
        "objectClass": "Product",
        "status": "pending"
    }]
}
```

Apply exactly that preview by sending the token with the same object selector:

```json
{
    "objectId": 42,
    "planToken": "v1...",
    "async": true
}
```

The token is bound to the actor, current object state, computed operations, request selector, and
bundle configuration. A changed object or operation, a changed selector, an expired token, or token
reuse returns `409`; an absent or malformed token returns `400`. Synchronous applies perform the same
final check after acquiring the organizer's object lock. Queued applies repeat that check in the
worker and mark stale work skipped without applying or requeueing a different plan.

Immediately before accepting an apply, the API re-checks object publish permission, source-asset
view and publish permission, and create permission on the nearest existing target folder. A revoked
or out-of-workspace boundary returns `403` before the plan is claimed or a message is queued. The
worker resolves the persisted actor again and repeats the mutation checks, so permission changes
after enqueue also prevent the move.

#### Bulk organize preview and apply

Preview the exact class selector or ID set through `/organize/bulk` itself:

```json
{
    "className": "Product",
    "dryRun": true
}
```

Or preview explicit IDs:

```json
{
    "objectIds": [1, 2, 3, 4, 5],
    "dryRun": true
}
```

The response contains `dryRun`, `planToken`, `objectCount`, and `operations`. Apply using the same
selector and token:

```json
{
    "objectIds": [1, 2, 3, 4, 5],
    "planToken": "v1...",
    "async": true,
    "batchSize": 50
}
```

For a class selector, the resolved object IDs are part of the signature. Objects added, removed, or
changed after preview therefore require a new preview. `/operations/bulk-preview` only pages through
objects for selection UIs and does not issue a token suitable for `/organize/bulk`.

Bulk apply performs the same whole-request object, source-asset, and target-folder authorization
preflight before creating its operation run or queue batches.

#### Reorganize and replay preview and apply

Both operation endpoints preview by default. The preview resolves the exact object selection,
computes its move operations, and returns `dryRun: true`, a single-use `planToken`, the selection
summary, `objectCount`, and `operations`. For example:

```json
{
    "folder": "/Staging",
    "limit": 200,
    "dryRun": true
}
```

Apply the exact reviewed selection by repeating the same selector with the token:

```json
{
    "folder": "/Staging",
    "limit": 200,
    "dryRun": false,
    "planToken": "v1...",
    "async": true
}
```

Replay uses the same contract with its `since`, `rule`, `class`, and `limit` selector. The token is
bound to that selector, the actor, configuration, resolved object set, object fingerprints, and
computed operations. Missing or malformed tokens return `400`; changed selections, changed objects,
expired tokens, and token reuse return `409`. Object publish access is checked again before apply
and in the worker. A synchronous apply returns the completed `runId`, `statusUrl`, per-object
results, and observer warnings. Async apply returns `202` with one batch `runId` and `statusUrl`.

The `asset-pilot:reorganize-assets` and `asset-pilot:replay-failures` commands use the same reviewed
selection executor. Their preview prints a `plan token`; `--apply` requires that exact token and
selector. CLI plans and queued messages are explicitly bound to the trusted System actor, while
HTTP plans remain bound to the authenticated user, so tokens cannot cross those authority scopes.

#### Journal recovery and dead-delivery retry

Both administrative recovery endpoints preview by default. The limit must be an integer from 1 to
1000. A preview returns a signed, single-use `planToken` bound to the authenticated administrator,
effective configuration, and exact current row fingerprints:

```json
{
    "limit": 100,
    "apply": false
}
```

Apply repeats the same limit and supplies that token:

```json
{
    "limit": 100,
    "apply": true,
    "planToken": "v1..."
}
```

`/operations/recovery` classifies stale journal entries as committed, failed, or still
`recovery_required` while holding the shared asset lock and restoring the recorded actor. It
updates only the journal and never repeats the original move or revert. The response contains
`applied`, `planToken`, `unresolved`, and exact `results`.

`/operations/deliveries/retry` reviews dead outbox rows and atomically resets only those exact
reconciled rows to pending. Stable delivery IDs, original operation intent, payload, and actor are
preserved. Dead and delivered rows whose audit update is still pending remain maintenance-due and are
not redelivered. The response contains `applied`, `planToken`, `count`, and exact `deliveries`,
including each row fingerprint. The operation warning remains until every unresolved delivery
succeeds.

Missing or invalid request values and malformed tokens return `400`. Changed, expired, or reused
plans return `409`. Both endpoints require the Asset Pilot Admin permission.

#### Explain response

```json
{
    "objectId": 42,
    "operations": [{
        "assetId": 456,
        "sourcePath": "/uploads/photo.jpg",
        "targetPath": "/Products/ART-123/Images/photo.jpg",
        "ruleName": "product_images",
        "status": "pending"
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

`/organize/explain` is View-only and never mutates. Its `operations[].status` is therefore always
`pending` (a move this object would make) or `skipped` (a matched asset the move is skipped for:
already at target, locked, in an excluded folder, strategy-rejected, or cancelled by a listener);
a terminal status such as `completed` cannot appear on this endpoint.

### Rules

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/rules` | View | List all configured rules |
| `GET` | `/rules/export` | View | Export the rule set as a portable artifact (`{format_version, rules}`) |
| `POST` | `/rules/diff` | View | Diff a posted rule-set artifact against the current rules (`added`/`removed`/`changed`/`unchanged`) |
| `GET` | `/rules/overlap` | View | Potentially competing rules after excluding obvious disjoint locale/type/extension/size gates (`{overlaps[]}`) |
| `GET` | `/rules/drift?class=Product` | View | Assets not at their rule-expected path (paged and bounded; each item includes `eligibility` and nullable `reason`) |
| `GET` | `/rules/{name}` | View | Rule details with statistics |
| `GET` | `/rules/{name}/preview?objectId=42` | View | Preview rule against an object |
| `POST` | `/rules/{name}/apply` | Operate | Apply the exact single-rule preview (`{objectId, planToken}`); the actor-bound token is single-use and stale previews return `409` |

Rule apply is a two-step operation. First request
`GET /rules/{name}/preview?objectId=42`, then submit the returned `planToken` with the same
`objectId`. The token binds the actor, rule configuration, object modification time, target assets,
and computed operations. It can be claimed once and fails closed if any bound input changed.

### Asset Management

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/assets/search` | View | Search assets (params: `q`, `type`, `folder`, `objectId`, `extension`, `referenced`, `page`, `limit`, `sort`, `order`) |
| `POST` | `/assets/download-zip` | View | Build and download a ZIP of the given asset ids (`{assetIds[], strategy?, thumbnail?}`) |
| `POST` | `/assets/download-zip/prepare` | View | Create a short-lived, current-user-bound token for a native browser ZIP download |
| `GET` | `/assets/download-zip/{token}` | View | Build and stream the ZIP for a prepared token without browser-side buffering |
| `GET` | `/assets/tags` | View + Pimcore `tags_assignment` | Paginated tag discovery (`page`, `limit` up to 200, optional literal `q` search); returns `items`, `total`, `page`, `limit`, and `pages` |
| `POST` | `/assets/{id}/lock` | Operate | Lock asset from organization |
| `DELETE` | `/assets/{id}/lock` | Operate | Unlock asset |
| `POST` | `/assets/bulk-tag` | Operate + Pimcore `tags_assignment` | Bulk assign tags to assets through a signed apply plan (preview, then apply) |
| `POST` | `/assets/bulk-property` | Operate | Bulk set custom properties through a signed apply plan (preview, then apply) |

Both ZIP-streaming responses (`POST /assets/download-zip` and the prepared-token `GET
/assets/download-zip/{token}`) carry four archive-accounting headers: `X-Asset-Pilot-Requested`
(asset ids in the selection), `X-Asset-Pilot-Added` (files packed into the archive),
`X-Asset-Pilot-Skipped` (ids dropped because the asset was missing, a folder, not viewable, or had
no packable file), and `X-Asset-Pilot-Truncated` (`true` or `false`). A complete build accounts for
every requested id as either added or skipped and returns `X-Asset-Pilot-Truncated: false`; `true`
marks a partial archive that did not account for every requested id. A selection above the
configured ZIP asset ceiling is rejected with `422` rather than returned as a truncated archive. The
`prepare` endpoint returns JSON only and carries none of these headers.

#### Search parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `q` | `string` | Search by filename or path (LIKE match) |
| `type` | `string` | Filter by asset type: `image`, `document`, `video`, `audio`, `text`, `archive` |
| `folder` | `string` | Filter by folder path (e.g., `/Products/`) |
| `objectId` | `int` | Filter to assets referenced by a specific DataObject (via dependencies table) |
| `extension` | `string` | Filter by file extension (single, e.g. `jpg`) |
| `referenced` | `string` | Reference state: `referenced` or `unreferenced` |
| `page` | `int` | Page number (default: 1) |
| `limit` | `int` | Items per page (default: 50, max: 200) |
| `sort` | `string` | Sort field: `id`, `filename`, `type`, `modified_at` |
| `order` | `string` | Sort order: `asc` or `desc` |

Both bulk endpoints use the same two-step signed-apply-plan flow as the unused-asset bulk endpoints:
a `dryRun: true` preview returns a single-use `planToken`, then the apply repeats the exact same body
plus that token. Applying without a fresh `planToken` returns `400`
(`A planToken from a fresh dry-run preview is required.`); a stale, reused, or drifted plan returns
`409`. Every response carries the envelope `{<counter>, failed, errors, observerWarnings, dryRun,
planToken, eligible}` where `<counter>` is `tagged` for bulk-tag and `updated` for bulk-property.

#### Bulk tag request body

Preview with `dryRun: true`:

```json
{
    "assetIds": [1, 2, 3],
    "tagIds": [10, 20],
    "replace": false,
    "dryRun": true
}
```

The preview returns the zeroed counter set, the `eligible` count, and a single-use `planToken`:

```json
{
    "tagged": 0,
    "failed": 0,
    "errors": {},
    "observerWarnings": [],
    "dryRun": true,
    "planToken": "v1...",
    "eligible": 3
}
```

Apply the same body with `dryRun: false` plus the returned `planToken`:

```json
{
    "assetIds": [1, 2, 3],
    "tagIds": [10, 20],
    "replace": false,
    "dryRun": false,
    "planToken": "v1..."
}
```

Set `replace: true` to remove all existing tags before assigning new ones.

#### Bulk property request body

Preview with `dryRun: true`:

```json
{
    "assetIds": [1, 2, 3],
    "name": "department",
    "type": "text",
    "data": "Marketing",
    "dryRun": true
}
```

The preview returns the zeroed counter set, the `eligible` count, and a single-use `planToken`:

```json
{
    "updated": 0,
    "failed": 0,
    "errors": {},
    "observerWarnings": [],
    "dryRun": true,
    "planToken": "v1...",
    "eligible": 3
}
```

Apply the same body with `dryRun: false` plus the returned `planToken`:

```json
{
    "assetIds": [1, 2, 3],
    "name": "department",
    "type": "text",
    "data": "Marketing",
    "dryRun": false,
    "planToken": "v1..."
}
```

Supported types: `text`, `bool`, `select`.

### Unused Assets

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/unused-assets` | View | List unused assets with confidence scoring |
| `GET` | `/unused-assets/stats` | View | Unused asset statistics by type |
| `GET` | `/unused-assets/export` | View | Stream the unused-asset listing as CSV (same filters as `/unused-assets`) |
| `POST` | `/unused-assets/bulk-delete` | Operate | Preview or delete unused assets through a signed apply plan |
| `POST` | `/unused-assets/bulk-move` | Operate | Preview or move unused assets to a folder through a signed apply plan |
| `POST` | `/unused-assets/bulk-quarantine` | Operate | Preview or quarantine unused assets through a signed apply plan |
| `GET` | `/quarantine` | View | List quarantined assets with their original path (`?type`, `?before`, `?after`, `?page`, `?limit` max 200) |
| `POST` | `/quarantine/{assetId}/restore` | Operate | Move a quarantined asset back to its original path |
| `GET` | `/quarantine/export` | View | Stream the quarantine list as CSV (`?type`, `?before`, `?after`) |

All three bulk mutation endpoints require two calls. Preview with the exact intended request and
`"dryRun": true`; the response includes a single-use `planToken`, `eligible`, per-asset `errors`, and
the normal mutation counter set to zero. Apply the same asset IDs and action options with
`"dryRun": false` and that `planToken`:

```json
{
    "assetIds": [12, 34],
    "targetFolder": "/Archive/Unused",
    "dryRun": true
}
```

```json
{
    "assetIds": [12, 34],
    "targetFolder": "/Archive/Unused",
    "dryRun": false,
    "planToken": "v1..."
}
```

`targetFolder` is present only for bulk move. The token binds the actor, action, exact ordered ID list,
target folder, protection configuration, and each asset's current path, modification time, lock
property, dependency rows, dependency projection verdict, and hard-coded content-reference state. Apply
rebuilds and claims that plan, then checks each fingerprint again while holding its asset lock.
Malformed or missing tokens return `400`; expired, reused, or changed plans return `409` and must be
previewed again.

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
| `sort` | `string` | Sort field: `id`, `filename`, `type`, `modified_at` |
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
| `GET` | `/audit/export` | View | Export as CSV (params: `class`, `status`, `ruleName`) |
| `POST` | `/audit/{id}/revert` | Admin | Revert a completed operation |
All controller routes are registered with Studio's OpenAPI scanner under the `Asset Pilot` tag. The
tables above remain the detailed request and response contract for the current release.
