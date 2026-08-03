[← Documentation index](index.md) · [Project README](../README.md)

# Commands

### Rebuild Dependency Projection

```bash
# Process the configured bounded batch and persist the cursor
bin/console asset-pilot:rebuild-dependency-projection

# Override this invocation's bound, or deliberately start a new generation
bin/console asset-pilot:rebuild-dependency-projection --limit=2500
bin/console asset-pilot:rebuild-dependency-projection --restart
```

Rerun until the state is `ready`. Destructive reference checks distinguish `safe`, `referenced`, and
`unknown`; incomplete bootstrap, dirty sources, or rebuild failure produce `unknown` and fail closed.

### Organize Assets

```bash
# Preview a single object and receive a signed plan token
bin/console asset-pilot:organize --object-id=42

# Apply that exact single-object preview inline
bin/console asset-pilot:organize --object-id=42 --apply --plan-token='v1...'

# Preview all objects of a class, bounded to 1000 objects
bin/console asset-pilot:organize --class=Product --batch-size=100

# Queue the exact reviewed class selection through Messenger
bin/console asset-pilot:organize --class=Product --batch-size=100 --apply --plan-token='v1...' --async

# Verbose single-object preview shows the full rule evaluation per asset
bin/console asset-pilot:organize --object-id=42 -v
```

The bundle-owned preview pipeline does not persist mutations and prints a signed, single-use plan
token. Consumer strategies and `PRE_MOVE` listeners also run during preview and must honor their
query-only contract. Apply requires the same
selector plus `--apply --plan-token=...`. The token binds the System actor, exact sorted object IDs,
object fingerprints, active configuration, trigger and computed operations. Changed selections,
expired or malformed tokens, and token reuse are rejected before mutation. Both inline and async
apply create a durable operation run and print its ID and status. Class selection is capped at 1000
objects; use a narrower selector when the class is larger.

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
# Preview the objects and moves selected from a staging folder
bin/console asset-pilot:reorganize-assets --folder=/Staging --limit=200

# Preview the owners of specific assets only (e.g. the ids a just-finished import returned)
bin/console asset-pilot:reorganize-assets --by-ids=1024,1025,1026

# Apply the exact preview inline, or queue it as one tracked operation run
bin/console asset-pilot:reorganize-assets --folder=/Staging --limit=200 --apply --plan-token='v1...'
bin/console asset-pilot:reorganize-assets --folder=/Staging --limit=200 --apply --plan-token='v1...' --async
```

Asset-centric counterpart to `organize` (which is object-first): for assets in `--folder` (or the
explicit `--by-ids` set), it resolves the owning DataObjects (reverse dependencies) and re-organizes
each, relocating the assets to their rule-derived paths. The scan is bounded both by `--limit` (the
result cap) and by the `listing.scan_budget` raw-candidate ceiling; if the budget is hit before the
limit is filled the command warns that the selection is truncated and more assets may remain. Each
owner is organized once. Preview is side-effect free and prints a signed `plan token`. Applying
requires `--apply --plan-token=...` with the exact same selector, including the folder and limit or
the sorted asset IDs. The token binds the System actor used by the trusted CLI, resolved owner IDs,
current object fingerprints, active configuration, and computed operations. Missing or malformed
tokens are rejected; changed objects or selectors, expired tokens, and token reuse require a new
preview. Both inline and async apply create one durable operation run and print its ID and status.
Async apply dispatches the reviewed IDs and fingerprints as one batch; inline apply records each
object result before finishing the run.

### Verify Locations (Drift)

```bash
# Report assets no longer at the path the current rules expect (organization drift)
bin/console asset-pilot:verify-locations --class=Product --limit=100

# Check drift for a single object instead of scanning a whole class
bin/console asset-pilot:verify-locations --object-id=42
```

After a rule change, already-organized assets stay in their old location. This resolves expected
paths over a bounded, paged set of objects of `--class` (or a single `--object-id`) and lists each
asset whose actual path differs from the rule-expected path. The result includes move eligibility
and any known block such as an asset lock, excluded folder, or completed first assignment. Callback
strategies and pre-move listeners are not invoked by this read-only audit; callback eligibility is
reported as requiring an apply-time check. It is read-only;
re-organize via `asset-pilot:organize` or the Operations tab. A whole-catalog sweep should page
through with `--page` rather than one blocking pass.

### Rule Overlap

```bash
# Report rules that compete for the same assets (same class + overlapping fields)
bin/console asset-pilot:rule-overlap
```

Static analysis of the rule set only (no catalog scan): for each potentially overlapping pair it
shows the shared class, the shared fields, and which rule wins by priority. Clearly disjoint locale,
type, extension, and size filters are excluded. Arbitrary conditions are not executed, so remaining
pairs are potential rather than proven catalog collisions. Overlap can be intentional (a
wildcard fallback alongside specific rules); same-priority overlaps are ambiguous and should be
re-prioritized. A catalog-wide "what would rule X move" impact report is a separate, async concern.

### Replay Failures

```bash
# Preview every object whose organization failed
bin/console asset-pilot:replay-failures

# Scope by time, rule, or class; preview first and retain the printed token
bin/console asset-pilot:replay-failures --since="-7 days" --rule=product_images --limit=200

# Apply that exact preview via Messenger
bin/console asset-pilot:replay-failures --since="-7 days" --rule=product_images --limit=200 --apply --plan-token='v1...' --async

# Preview or apply specific objects only
bin/console asset-pilot:replay-failures --object-id=42,43
bin/console asset-pilot:replay-failures --object-id=42,43 --apply --plan-token='v1...'
```

Reads the distinct failed objects from the audit log (bounded by `--limit`, grouped per object so a
flood of failures is not an unbounded scan) and re-organizes each. With `--object-id` it re-organizes
the explicitly selected objects. Preview is side-effect free and prints a signed `plan token`.
Applying requires that token plus the identical audit selectors, limit, and explicit object IDs.
The token is single-use, short-lived, and bound to the CLI System actor, resolved IDs, object
fingerprints, configuration, and computed operations; it cannot be exchanged with an HTTP user's
token. Both inline and async apply print a durable run ID and status. Async execution carries the
same System actor and reviewed fingerprints to the worker. Exits non-zero for a stale, reused, or
failed apply.

### Recover Stale Operations

```bash
# Inspect stale move/revert journal entries without changing the journal or assets
bin/console asset-pilot:recover-operations --limit=100

# Finalize only the exact reviewed operation IDs
bin/console asset-pilot:recover-operations --limit=100 --apply --plan-token='v1...'
```

Recovery never repeats a move or revert. It acquires the shared asset lock, restores the actor stored
in the journal, rechecks the actor's publish permission, and classifies the force-reloaded asset as
committed, not committed, or still uncertain. Preview prints a signed single-use token bound to the
System actor, limit, recovery configuration, exact operation IDs, and classifications. Apply rejects
changed, expired, malformed, or reused plans. Uncertain states remain `recovery_required` and make the
command exit non-zero for manual investigation.

### Retry Dead Deliveries

```bash
# Inspect dead durable observer deliveries without changing delivery state
bin/console asset-pilot:retry-deliveries --limit=100

# Requeue only the exact reviewed delivery rows
bin/console asset-pilot:retry-deliveries --limit=100 --apply --plan-token='v1...'
```

Preview prints a signed, single-use plan bound to the System actor, exact delivery row fingerprints,
and effective retry configuration. Apply atomically rejects changed, expired, malformed, or reused
plans. A dead row becomes reviewable only after its operation warning is durably reconciled; pending
dead and delivered reconciliation is retried by normal maintenance without repeating observer work.
Requeued rows retain their stable delivery ID and operation intent, reset their attempts and lease,
and run again through the normal actor restoration, authorization, lock, and idempotency
checks. The operation's observer warning remains visible until every unresolved delivery succeeds.

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
render-tested, so the scan is bounded (`--limit`, default 100). Narrow a scan with `--folder`, `--type`
(image, document, ...), and `--extension`; use `--by-ids` to check exactly the assets you care about.
Detection only; rolling back to a working version is a separate, guarded heal.

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
bounded, paged pass (capped by `--limit`, default 1000; reusing Pimcore's stream-hashed MD5, folders
and unhashable assets skipped). `--folder`, `--type`, and `--extension` restrict both scanning and
reporting. Reporting is then a single indexed `GROUP BY` over that index, so it never re-hashes the
catalog and never blocks. For a whole catalog, run `--scan` in batches (raise `--limit`, or scan per
`--folder`/`--type`/`--extension`). The report itself is capped at `--report-limit` duplicate groups
(default 50), so raise it to see more than 50 groups. Detection only; merging duplicates (re-pointing
references, deleting the copy) is a separate, guarded step.

The index is a snapshot from the last `--scan`: an asset deleted or re-uploaded afterwards stays
listed (or stale) until the next scan, so re-scan or verify an id before acting on it.

### Merge Duplicates

```bash
# Preview a merge of one byte-identical group onto its canonical asset (no writes)
bin/console asset-pilot:merge-duplicates --checksum=<hash> --canonical=123 --strategy=delete

# Apply the exact preview with the same selectors
bin/console asset-pilot:merge-duplicates --checksum=<hash> --canonical=123 --strategy=delete --apply --plan-token='v1...'

# Resume a prepared or interrupted merge run from its persisted phase
bin/console asset-pilot:merge-duplicates --run-id=<32-character-run-id> --apply
```

Consolidates a group: picks the canonical (lowest id, or `--canonical`), repoints each copy's
references onto it, then disposes the copy per `--strategy` (default from `duplicates.merge_strategy`:
`quarantine` / `delete` / `isolate`, or a custom one). Preview issues a signed, single-use plan bound
to the system actor, checksum, exact sorted assets and fingerprints, canonical asset, strategy, and
available-strategy configuration. Apply requires the same selectors plus
`--apply --plan-token=...`; drift, expiry, and token reuse are rejected before a durable operation run
is created. An interrupted applied run can continue with `--run-id --apply` without another plan
token because that run already persists the reviewed state. A copy whose references are not fully repointed
(documents, nested bricks/blocks/fieldcollections, advanced relations) is reported blocked and left
untouched, never deleted. The disposition seam is documented in
[Extending](extending.md#duplicate-merge-strategies).

### Heal Assets (version rollback)

```bash
# Preview: which broken assets could be rolled back, and receive a signed plan token
bin/console asset-pilot:heal-assets --by-ids=1024,1025

# Apply the exact reviewed rollback before the token expires
bin/console asset-pilot:heal-assets --by-ids=1024,1025 --apply --plan-token='v1...'

# Preview, then reverse the most recent heal of specific assets
bin/console asset-pilot:heal-assets --undo --by-ids=1024
bin/console asset-pilot:heal-assets --undo --by-ids=1024 --apply --plan-token='v1...'
```

The destructive counterpart to `check-integrity`: for each broken asset it walks versions
newest-to-oldest, finds the first whose stored binary renders (probed without touching the live
asset), and restores it. The restore save is LoopGuard-wrapped so it does not re-enter the organize
pipeline, and every heal is recorded so `--undo` can reverse it (a renderable version can still be
the wrong content). An asset that is broken with no renderable version is reported (and, when
`integrity.on_unrecoverable: quarantine`, best-effort quarantined if still unused), never silently
left. A missing/unsupported render tool yields `unverifiable` and the asset is never healed. Bound
the run with `--by-ids` or the scan filters (`--folder`/`--type`/`--extension`) plus `--limit`; an
unfiltered whole-catalog scan must be explicitly requested with `--all`. For a large catalog run it in
batches.
Preview is mandatory. It returns a signed, single-use plan bound to the system actor, exact sorted
asset ids, selectors, integrity configuration, live asset/version state and proposed outcome. Apply
requires the same selector plus `--apply --plan-token=...`; changed selections or asset state,
changed undoable heal records, expired tokens and reused tokens are rejected before the first
mutation.

### Normalize Filenames

```bash
# Preview exact filename changes and receive a signed plan token
bin/console asset-pilot:normalize-filenames --by-ids=1024,1025

# Apply that exact reviewed selection before the token expires
bin/console asset-pilot:normalize-filenames --by-ids=1024,1025 --apply --plan-token='v1...'

# Or normalize by scanning a folder/type (bounded by --limit, default 100)
bin/console asset-pilot:normalize-filenames --folder=/Products --type=image --limit=200
```

Renames assets whose filename is not a valid Pimcore key to the sanitized form (via the native
`Element\Service::getValidKey`). Select either with `--by-ids` or by scanning with `--folder`,
`--type`, and `--extension` (bounded by `--limit`, default 100); preview and apply with a plan token
work identically for a scanned selection. Destructive (a rename changes the path), so it previews by default
and only renames with `--apply`; each rename is per-asset permission-checked, skipped when the asset
is referenced in object content (a hard-coded path would break, the same guard as move/delete when
`content_scan` is enabled), and LoopGuard-wrapped so it does not re-enter the organize pipeline.
Already-valid filenames are left untouched. Preview returns a signed, single-use system plan bound
to the exact sorted ids, selector and per-asset proposed filename or rejection descriptor. Apply
requires the same selector plus `--apply --plan-token=...`; selection or descriptor drift, expiry
and token reuse are rejected before any rename.

### Sweep Empty Folders

```bash
# Preview empty asset folders and receive a signed plan token
bin/console asset-pilot:sweep-empty-folders --folder=/Products --limit=1000

# Delete the exact reviewed folders
bin/console asset-pilot:sweep-empty-folders --folder=/Products --apply --plan-token='v1...'
```

Cleanup leaves empty folders behind. This finds folders with no children via a paged `NOT EXISTS`
query (no full-tree walk, never blocks). Preview issues a signed, single-use system plan bound to the
folder selector, limit, exact sorted folder ids and folder-state fingerprints. Apply requires the
same selector plus `--apply --plan-token=...`, then locks and re-verifies every folder before the first
delete. Changed selections, folders that gained content, expired tokens, and reused tokens are
rejected without recursively deleting anything. The asset tree root is never a candidate. The pass is
bounded by `--limit` (default 500, clamped to 1..1000): one run removes up to that many current
leaf-empty folders, and a parent that only held those is swept on a subsequent run.

### Quarantine Purge

```bash
# Preview expired quarantined assets and receive a signed plan token
bin/console asset-pilot:quarantine-purge

# Apply the exact reviewed purge, optionally with an overridden grace period
bin/console asset-pilot:quarantine-purge --apply --plan-token='v1...'
bin/console asset-pilot:quarantine-purge --grace-days=7 --apply --plan-token='v1...'
```

Quarantine (via `cleanup-unused` or the Studio bulk action) is a reversible soft-delete: assets are
moved to `quarantine.folder`, not deleted. The command previews by default and issues a signed,
single-use system plan bound to the effective grace period, exact sorted candidates, mutation-safety
configuration and live fingerprints. Apply requires `--apply --plan-token=...`, locks the complete
selection, and rejects selection or fingerprint drift before deleting anything. Each asset is still
re-checked for references and permissions immediately before deletion. Automatic Pimcore maintenance
continues to run the configured purge directly through `QuarantinePurgeTask`.

### Capture Storage Snapshot (trends)

```bash
# Record the current unused-asset count and byte size per type, for trend reporting
bin/console asset-pilot:capture-storage-snapshot
bin/console asset-pilot:capture-storage-snapshot --force
```

Each capture has a durable run header and atomically committed per-type rows. Zero-unused runs are
stored explicitly, failed/running runs are excluded from reports, duplicate type rows are rejected,
and old runs are pruned by `storage_snapshots.retention_days`. Because the assets table has no size
column, capture reads each unused asset's size from storage. Maintenance and the CLI therefore honor
`storage_snapshots.minimum_interval_seconds`; use `--force` only for an intentional extra scan.
Sizes come from the bounded checksum index rather than one remote storage request per dashboard row.
Run `asset-pilot:find-duplicates --scan` on a schedule to refresh both checksums and sizes. Assets
missing from that index, assets modified after indexing, and storage read failures are reported as
unknown sizes; they are never added to the known-byte total as zero.

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
- `database_schema`: all fifteen owned tables, columns, primary keys, and indexes match the current
  migration target (CRITICAL when missing or drifted).
- `rule_config`: the loaded rules pass `validate-config` (CRITICAL on a failure, WARNING on a warning).
- `shared_cache`: reports WARNING for a known process-local adapter or when cross-process
  visibility cannot be proven. Fresh heartbeats written by both independent worker processes prove
  shared visibility even when the adapter type itself is not recognizable.
- `async_transport`: always verifies durable-delivery routing, and additionally verifies organize
  and bulk routing when async organization is enabled. It checks the configured receiver and failure
  receiver, inspects native queue depths, warns on backlog or failed messages, and always requires
  fresh `pimcore_maintenance` and configured Asset Pilot receiver heartbeats (the receiver is
  `asset_pilot` by default, or whatever `async.transport` is set to).
- `operation_journal`: warns on stale unfinished operations or overdue deliveries and reports
  CRITICAL for recovery-required operations or dead observer deliveries.
- `dependency_tracking`: reports live projection generation, cursor, source/edge counts, and dirty
  sources. It is OK only when Pimcore tracking is enabled and the projection is ready and clean,
  WARNING while bootstrap/rebuild/dirty-source repair is pending, and CRITICAL when disabled or the
  rebuild failed.
- `operation_run_backlog`: WARNING when one or more operation runs have stayed awaiting dispatch
  (`pending_dispatch`, an unscheduled maintenance relay) or `queued` longer than
  `operation_runs.stale_queued_warning_seconds` (default 86400s / 24h), which can indicate a lost
  broker message. A queued run is never age-failed (a genuine broker backlog is left alone), so an
  operator should cancel and retry the run or verify the consumers. This check never returns CRITICAL.

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
bin/console asset-pilot:cleanup-unused --all

# Apply the exact reviewed irreversible deletion
bin/console asset-pilot:cleanup-unused --type=image --before="-90 days" --action=delete --apply --confirm-delete --plan-token='v1...'

# Apply the exact reviewed move to an archive folder
bin/console asset-pilot:cleanup-unused --all --action=move --move-to="/Archive/Unused" --apply --plan-token='v1...'

# Filter by extension and folder
bin/console asset-pilot:cleanup-unused --extension=jpg,png --folder=/uploads/temp

# Preview specific ids, then apply after review
bin/console asset-pilot:cleanup-unused --by-ids=1024,1025 --action=delete
bin/console asset-pilot:cleanup-unused --by-ids=1024,1025 --action=delete --apply --confirm-delete --plan-token='v1...'
```

Preview prints a signed, single-use token bound to the exact sorted candidate IDs, selector,
effective cleanup configuration and live asset fingerprints. Apply requires the same options plus
`--apply --plan-token=...`; deletion additionally requires `--confirm-delete`. The command rejects
selection or fingerprint drift, expired or malformed tokens, and token reuse before changing any
asset.

Selectors: `--before` and `--after` bound the modification date (either accepts an absolute date or a
relative expression such as `-90 days`), `--type` and `--extension` take comma-separated lists, and
`--folder` limits to a subtree. `--batch-size` (default 100) sets the processing batch. A scanned run
is capped by `--max-assets` (default 1000, which is also the hard maximum): a scope larger than the cap
fails with an error asking you to narrow the selector rather than silently truncating, so narrow the
selector (by folder, date, or type) across successive runs for a large catalog. `--max-assets` cannot be
raised above 1000. `--by-ids` bypasses scanning (each id is still re-verified as unused and
permission-checked).

> Note: with `content_scan.enabled` (see [Configuration](configuration.md)), delete and move also
> skip an asset whose path is hard-coded in supported Pimcore object, nested, document, property,
> or classification-store content, a reference the dependency table may not track. The guard runs
> only on assets being mutated and fails closed when it cannot verify safety.

> Note: the unused-asset cleanup cannot filter by file size. The Pimcore `assets` table has no
> size column, and post-filtering after pagination would corrupt the count on a delete path, so
> `minSize`/`maxSize` are rejected rather than silently ignored. Rule-level `filters.min_size` /
> `filters.max_size` (see the rule reference) still work, because the asset is in memory during
> organization.

### Download Zip

Build a ZIP of assets to a file (non-blocking, for cron or workers). Provide exactly one source.

```bash
# By asset ids, flat layout
bin/console asset-pilot:download-zip --asset-ids=1024,1025 --output=/tmp/assets.zip

# Every asset under a folder, mirrored folder tree, packed as a named thumbnail
bin/console asset-pilot:download-zip --folder-id=42 --strategy=folder --thumbnail=web --output=/tmp/folder.zip

# Assets referenced by data objects, grouped by type, direct children only
bin/console asset-pilot:download-zip --object-ids=900,901 --strategy=type --non-recursive --output=/tmp/objects.zip
```

`--strategy` is `flat`, `folder`, `type`, or a custom `oronts_asset_pilot.zip_strategy` name. `--output`
is required, existing files require `--force`, and the archive is capped by `zip.max_assets` and
`zip.max_uncompressed_bytes`. Unreadable or empty assets are skipped, not
packed.

### Scheduled Jobs (Cron)

Read-only reports can be automated directly. Mutating reviewed-plan commands require a fresh token,
so an unattended job must preview and apply immediately through an orchestrator that validates the
preview result; never store a token in a crontab.

```bash
# --- Nightly ---
# Preview Product organization for review
0 2 * * * cd /var/www/html && bin/console asset-pilot:organize --class=Product --batch-size=100 >> /var/log/asset-pilot-preview.log 2>&1

# --- Weekly ---
# Generate unused asset report (dry-run, no changes)
0 3 * * 0 cd /var/www/html && bin/console asset-pilot:cleanup-unused --type=image 2>&1 | mail -s "Unused Assets Report" admin@example.com

# --- Monthly ---
# Clean up old audit log entries
0 5 1 * * cd /var/www/html && bin/console asset-pilot:audit --cleanup >> /var/log/asset-pilot.log 2>&1
```

Audit-log retention also runs automatically as a Pimcore maintenance task (`AuditRetentionTask`,
registered under `pimcore.maintenance.task`), so it is pruned to `audit.retention_days` on every
maintenance run without a dedicated cron entry. Operation-run retention and quarantine purge are
also registered maintenance tasks; their configured policies do not require stored CLI plan tokens.
The cron entry above stays valid for an explicit audit schedule.

These maintenance tasks, and the organize dispatch relay that publishes listener-created automatic
organize runs (`OrganizeDispatchRelayTask`), only run when `pimcore:maintenance` is scheduled. It is a
command, not a daemon, so schedule it from cron or a timer; otherwise automatic organization stays in
`pending_dispatch` and the retention/purge tasks never run:

```bash
# Dispatch Pimcore maintenance tasks (organize relay, retention, quarantine purge, storage snapshots)
* * * * * cd /var/www/html && flock -n /tmp/pimcore-maintenance.lock bin/console pimcore:maintenance
```

The `pimcore:maintenance` scheduler dispatches the task messages onto the `pimcore_maintenance`
transport; the `pimcore_maintenance` Messenger consumer executes them (see installation.md).

```bash

# --- CI/CD ---
# Validate config after deployments
# bin/console asset-pilot:validate-config
# bin/console asset-pilot:debug-rule --object-id=42
```

For Kubernetes/Docker environments, use CronJob resources or container-level cron scheduling.
