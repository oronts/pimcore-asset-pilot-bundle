[← Documentation index](index.md) · [Project README](../README.md)

# Commands

### Organize Assets

```bash
# Organize a single object
bin/console asset-pilot:organize --object-id=42

# Dry run (preview without moving)
bin/console asset-pilot:organize --object-id=42 --dry-run

# Bulk organize all objects of a class
bin/console asset-pilot:organize --class=Product

# Async bulk (dispatch to messenger queue)
bin/console asset-pilot:organize --class=Product --async --batch-size=100

# Verbose dry run — shows full rule evaluation per asset
bin/console asset-pilot:organize --object-id=42 --dry-run -v
```

### Validate Configuration

```bash
# Validate all configured rules
bin/console asset-pilot:validate-config
```

Checks performed:
- Class exists in Pimcore (`ClassDefinition::getByName()`)
- Fields exist in the class definition (including localized fields)
- ExpressionLanguage condition syntax is valid
- Twig path template syntax is valid
- Callback service exists in the DI container (when strategy=callback)
- Filter type/extension values are valid
- Warns on duplicate priorities for the same class

### Export and Diff Rules

Rules are config-only (see [Configuration](configuration.md)). These commands move a rule set
between environments: export the current set to a portable artifact, then diff it against another
environment's rules. Neither writes rules; the diff output is the config you commit.

```bash
# Export the current rule set as JSON (stdout) or YAML, optionally to a file
bin/console asset-pilot:rules-export
bin/console asset-pilot:rules-export --format=yaml --output=rules.yaml

# Diff an exported artifact against the rules currently loaded (reads nothing into the DB)
bin/console asset-pilot:rules-diff rules.yaml

# Fail the command if the artifact differs from the current rules (CI drift gate)
bin/console asset-pilot:rules-diff rules.yaml --fail-on-diff
```

The diff classifies each rule as added, removed, changed, or unchanged. `rules-diff` first validates
the imported rules (reusing `validate-config`) and aborts if any are invalid.

### Reorganize Assets (post-import)

```bash
# Re-organize the objects that own the assets sitting in a staging folder
bin/console asset-pilot:reorganize-assets --folder=/Staging --limit=200

# Re-organize the owners of specific assets only (e.g. the ids a just-finished import returned)
bin/console asset-pilot:reorganize-assets --by-ids=1024,1025,1026

# Queue each owner via the messenger worker instead of running inline
bin/console asset-pilot:reorganize-assets --folder=/Staging --async
```

Asset-centric counterpart to `organize` (which is object-first): for assets in `--folder` (or the
explicit `--by-ids` set), it resolves the owning DataObjects (reverse dependencies) and re-organizes
each, relocating the assets to their rule-derived paths. The scan is bounded by `--limit` and each
owner is organized once; re-organizing is idempotent.

### Verify Locations (Drift)

```bash
# Report assets no longer at the path the current rules expect (organization drift)
bin/console asset-pilot:verify-locations --class=Product --limit=100

# Check drift for a single object instead of scanning a whole class
bin/console asset-pilot:verify-locations --object-id=42
```

After a rule change, already-organized assets stay in their old location. This dry-runs the current
rules over a bounded, paged set of objects of `--class` (or a single `--object-id`) and lists each
asset whose actual path differs from the rule-expected path (asset, rule, current path, expected
path). It is read-only;
re-organize via `asset-pilot:organize` or the Operations tab. A whole-catalog sweep should page
through with `--page` (or run async) rather than one blocking pass.

### Rule Overlap

```bash
# Report rules that compete for the same assets (same class + overlapping fields)
bin/console asset-pilot:rule-overlap
```

Static analysis of the rule set only (no catalog scan): for each overlapping pair it shows the
shared class, the shared fields, and which rule wins by priority. Overlap can be intentional (a
wildcard fallback alongside specific rules); same-priority overlaps are ambiguous and should be
re-prioritized. A catalog-wide "what would rule X move" impact report is a separate, async concern.

### Replay Failures

```bash
# Re-run every object whose organization failed (idempotent: already-placed assets skip)
bin/console asset-pilot:replay-failures

# Scope by time, rule, or class; queue via Messenger; cap the candidate set
bin/console asset-pilot:replay-failures --since="-7 days" --rule=product_images --async --limit=200

# Replay specific objects only, instead of every failed object
bin/console asset-pilot:replay-failures --object-id=42,43
```

Reads the distinct failed objects from the audit log (bounded by `--limit`, grouped per object so a
flood of failures is not an unbounded scan) and re-organizes each. With `--object-id` it re-organizes
exactly those objects and never touches the audit log. `--async` queues an organize message per
object instead of running inline. Exits non-zero if any inline re-organize fails again.

### Check Integrity

```bash
# Scan for assets whose binary no longer renders (filter by folder/type; bounded by --limit)
bin/console asset-pilot:check-integrity --folder=/Products --limit=200

# Check specific assets only, instead of scanning
bin/console asset-pilot:check-integrity --by-ids=1024,1025
```

Runs the tagged integrity checkers (built-in: missing/empty binary, broken image, broken document).
A checker reports `unverifiable` rather than `broken` when its tool (e.g. Imagick) is absent, so the
feature never flags an asset broken just because a tool was missing. Each scanned asset is loaded and
render-tested, so the scan is bounded (`--limit`); use `--by-ids` to check exactly the assets you
care about. Detection only — rolling back to a working version is a separate, guarded heal.

### Find Duplicates

```bash
# Build/refresh the content-hash index for a folder, then report byte-identical assets
bin/console asset-pilot:find-duplicates --scan --folder=/Products --limit=5000

# Report duplicates from the existing index (no scan)
bin/console asset-pilot:find-duplicates

# Check one specific asset: is it duplicated, and which group is it in?
bin/console asset-pilot:find-duplicates --asset-id=123
```

Pimcore stores no checksum or filesize column on the `assets` table, so there is no SQL `GROUP BY
hash` to run against it. `--scan` populates an owned index (asset id, content hash, size) via a
bounded, paged pass (capped by `--limit`; reusing Pimcore's stream-hashed MD5, folders and
unhashable assets skipped). Reporting is then a single indexed `GROUP BY` over that index, so it
never re-hashes the catalog and never blocks. For a whole catalog, run `--scan` in batches (raise
`--limit`, or scan per `--folder`). Detection only; merging duplicates (re-pointing references,
deleting the copy) is a separate, guarded step.

The index is a snapshot from the last `--scan`: an asset deleted or re-uploaded afterwards stays
listed (or stale) until the next scan, so re-scan or verify an id before acting on it.

### Merge Duplicates

```bash
# Preview a merge of one byte-identical group onto its canonical asset (no writes)
bin/console asset-pilot:merge-duplicates --checksum=<hash>

# Apply it, choosing the canonical and the disposition strategy
bin/console asset-pilot:merge-duplicates --checksum=<hash> --canonical=123 --strategy=delete --apply
```

Consolidates a group: picks the canonical (lowest id, or `--canonical`), repoints each copy's
references onto it, then disposes the copy per `--strategy` (default from `duplicates.merge_strategy`:
`quarantine` / `delete` / `isolate`, or a custom one). Preview by default; `--apply` writes. A copy
whose references are not fully repointed (documents, nested bricks/blocks/fieldcollections, advanced
relations) is reported blocked and left untouched, never deleted. The disposition seam is documented in
[Extending](extending.md#duplicate-merge-strategies).

### Heal Assets (version rollback)

```bash
# Preview: which broken assets could be rolled back, and to which version (no write)
bin/console asset-pilot:heal-assets --folder=/Products --dry-run

# Roll broken assets back to their last renderable version
bin/console asset-pilot:heal-assets --by-ids=1024,1025

# Reverse the most recent heal of specific assets
bin/console asset-pilot:heal-assets --undo --by-ids=1024
```

The destructive counterpart to `check-integrity`: for each broken asset it walks versions
newest-to-oldest, finds the first whose stored binary renders (probed without touching the live
asset), and restores it. The restore save is LoopGuard-wrapped so it does not re-enter the organize
pipeline, and every heal is recorded so `--undo` can reverse it (a renderable version can still be
the wrong content). An asset that is broken with no renderable version is reported (and, when
`integrity.on_unrecoverable: quarantine`, best-effort quarantined if still unused), never silently
left. A missing/unsupported render tool yields `unverifiable` and the asset is never healed. Bound
the run with `--by-ids` or the scan filters plus `--limit`; for a large catalog run it in batches.

### Normalize Filenames

```bash
# Preview which assets have a non-normalized filename and what they would become
bin/console asset-pilot:normalize-filenames --folder=/uploads

# Rename specific assets to the sanitized form
bin/console asset-pilot:normalize-filenames --by-ids=1024,1025 --apply
```

Renames assets whose filename is not a valid Pimcore key to the sanitized form (via the native
`Element\Service::getValidKey`). Destructive (a rename changes the path), so it previews by default
and only renames with `--apply`; each rename is per-asset permission-checked, skipped when the asset
is referenced in object content (a hard-coded path would break, the same guard as move/delete when
`content_scan` is enabled), and LoopGuard-wrapped so it does not re-enter the organize pipeline.
Already-valid filenames are left untouched.

### Sweep Empty Folders

```bash
# Report empty asset folders (no children) under a subtree
bin/console asset-pilot:sweep-empty-folders --folder=/Products

# Delete them (re-verifies each is still empty + permission-checked first)
bin/console asset-pilot:sweep-empty-folders --folder=/Products --delete
```

Cleanup leaves empty folders behind. This finds folders with no children via a paged `NOT EXISTS`
query (no full-tree walk, never blocks) and, with `--delete`, removes them — re-verifying each is
still childless and permission-checked at delete time, so a folder that gained content since the
listing is skipped rather than recursively deleted. The asset tree root is never a candidate. One
pass removes the current leaf-empty folders; a parent that only held those is swept on the next run.

### Quarantine Purge

```bash
# Hard-delete quarantined assets older than the grace period (only those still unused)
bin/console asset-pilot:quarantine-purge

# Preview what would be purged, or override the grace period
bin/console asset-pilot:quarantine-purge --dry-run
bin/console asset-pilot:quarantine-purge --grace-days=7
```

Quarantine (via `cleanup-unused` or the Studio bulk action) is a reversible soft-delete: assets are
moved to `quarantine.folder`, not deleted. This purges entries past `quarantine.grace_days`, and
re-verifies each is still unused before deleting (an asset referenced again while quarantined is
skipped). It also runs automatically as a Pimcore maintenance task (`QuarantinePurgeTask`), so the
hard-delete is scheduled without a dedicated cron.

### Capture Storage Snapshot (trends)

```bash
# Record the current unused-asset count and byte size per type, for trend reporting
bin/console asset-pilot:capture-storage-snapshot
```

Snapshots the current unused storage per type into an owned table so `GET /storage/trends` can show
how it moves over time. Because the assets table has no size column, the capture reads each unused
asset's size from storage (a scan), so it runs on the Pimcore maintenance schedule
(`StorageSnapshotTask`) or via this command, never on a request; the trend report only reads the
snapshots. A run with nothing unused records no rows for that timestamp (the absence reads as zero in
the series).

### Metrics (Prometheus / JSON)

```bash
# Prometheus text exposition (default) — for a node_exporter textfile collector or a pushgateway
bin/console asset-pilot:metrics > /var/lib/node_exporter/textfile/asset_pilot.prom

# Or JSON, for ad-hoc scripting
bin/console asset-pilot:metrics --format=json
```

Emits the same audit-derived metrics as `GET /metrics` (operation counts per status, total, failure
rate, completed-move duration aggregate). It is a command rather than an HTTP endpoint because every
REST route here is permission-gated and a Prometheus scraper carries no Studio session; run it from
cron into a textfile collector (or pipe to a pushgateway) to scrape. Output is unstyled so it pipes
cleanly.

### Health Check

```bash
# Run all health checks; exits non-zero if any check is CRITICAL (CI/monitoring gate)
bin/console asset-pilot:health
```

Built-in checks (extensible via the `oronts_asset_pilot.health_check` tag):
- `audit_table` — the audit table exists when audit logging is enabled (CRITICAL if missing).
- `rule_config` — the loaded rules pass `validate-config` (CRITICAL on a failure, WARNING on a warning).
- `shared_cache` — `cache.app` is not a per-process store, so LoopGuard idempotency holds across
  workers (WARNING for a non-shared adapter; see [Architecture](architecture.md)).
- `async_transport` — reports the async configuration and reminds you to run a messenger worker.

### Debug Rules

```bash
# Debug rule evaluation for a specific object (shows all rules and why they matched/skipped)
bin/console asset-pilot:debug-rule --object-id=42

# Debug a specific asset against all rules
bin/console asset-pilot:debug-rule --object-id=42 --asset-id=100

# Filter to a single rule
bin/console asset-pilot:debug-rule --object-id=42 --rule=product_images

# Filter to a specific field
bin/console asset-pilot:debug-rule --object-id=42 --field=productImages
```

Output shows a table per rule with: rule name, result (MATCHED/SKIPPED), rejection reason (disabled, class_mismatch, field_mismatch, condition_failed, filter_rejected), condition expression and result, resolved target path, and priority.

### View Status

```bash
# Show configured rules and statistics
bin/console asset-pilot:status

# JSON output for scripting
bin/console asset-pilot:status --format=json
```

### Audit Log

```bash
# Recent operations
bin/console asset-pilot:audit --limit=50

# Filter by class and status
bin/console asset-pilot:audit --class=Product --status=completed --since="1 week ago"

# Filter by rule
bin/console asset-pilot:audit --rule=product_images

# Trace a single asset or object (e.g. "what happened to this asset?")
bin/console asset-pilot:audit --asset-id=1024
bin/console asset-pilot:audit --object-id=42

# Clean up old entries (respects retention_days config)
bin/console asset-pilot:audit --cleanup
```

### Clean Up Unused Assets

```bash
# Preview unused assets
bin/console asset-pilot:cleanup-unused --dry-run

# Delete unused images older than 90 days
bin/console asset-pilot:cleanup-unused --type=image --before="-90 days" --action=delete

# Move unused assets to archive folder
bin/console asset-pilot:cleanup-unused --action=move --move-to="/Archive/Unused"

# Filter by extension and folder
bin/console asset-pilot:cleanup-unused --extension=jpg,png --folder=/uploads/temp --dry-run

# Act on specific asset ids only (each is still re-verified as unused + permission-checked)
bin/console asset-pilot:cleanup-unused --by-ids=1024,1025 --action=delete
```

> Note: with `content_scan.enabled` (see [Configuration](configuration.md)), delete and move also
> skip an asset whose path is hard-coded into a configured class's rich-text/text fields — a
> reference the dependency table does not track. The guard runs only on the assets being mutated
> (not the listing) and fails closed (an unscannable asset is treated as referenced).

> Note: the unused-asset cleanup cannot filter by file size. The Pimcore `assets` table has no
> size column, and post-filtering after pagination would corrupt the count on a delete path, so
> `minSize`/`maxSize` are rejected rather than silently ignored. Rule-level `filters.min_size` /
> `filters.max_size` (see the rule reference) still work, because the asset is in memory during
> organization.

### Scheduled Jobs (Cron)

All commands can be automated via cron. Example crontab entries:

```bash
# --- Nightly ---
# Organize all Product assets (async, processed by messenger workers)
0 2 * * * cd /var/www/html && bin/console asset-pilot:organize --class=Product --async --batch-size=100 >> /var/log/asset-pilot.log 2>&1

# --- Weekly ---
# Generate unused asset report (dry-run, no changes)
0 3 * * 0 cd /var/www/html && bin/console asset-pilot:cleanup-unused --dry-run --type=image 2>&1 | mail -s "Unused Assets Report" admin@example.com

# Archive unused images older than 90 days
0 4 * * 0 cd /var/www/html && bin/console asset-pilot:cleanup-unused --type=image --before="-90 days" --action=move --move-to="/Archive/Unused" >> /var/log/asset-pilot.log 2>&1

# --- Monthly ---
# Clean up old audit log entries
0 5 1 * * cd /var/www/html && bin/console asset-pilot:audit --cleanup >> /var/log/asset-pilot.log 2>&1
```

Audit-log retention also runs automatically as a Pimcore maintenance task (`AuditRetentionTask`,
registered under `pimcore.maintenance.task`), so it is pruned to `audit.retention_days` on every
maintenance run without a dedicated cron entry. The cron entry above stays valid for an explicit
schedule.

```bash

# --- CI/CD ---
# Validate config after deployments
# bin/console asset-pilot:validate-config
# bin/console asset-pilot:debug-rule --object-id=42
```

For Kubernetes/Docker environments, use CronJob resources or container-level cron scheduling.
