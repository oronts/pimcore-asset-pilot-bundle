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

# Queue each owner via the messenger worker instead of running inline
bin/console asset-pilot:reorganize-assets --folder=/Staging --async
```

Asset-centric counterpart to `organize` (which is object-first): for assets in `--folder`, it resolves
the owning DataObjects (reverse dependencies) and re-organizes each, relocating the assets to their
rule-derived paths. The scan is bounded by `--limit` and each owner is organized once; re-organizing
is idempotent.

### Verify Locations (Drift)

```bash
# Report assets no longer at the path the current rules expect (organization drift)
bin/console asset-pilot:verify-locations --class=Product --limit=100
```

After a rule change, already-organized assets stay in their old location. This dry-runs the current
rules over a bounded, paged set of objects of `--class` and lists each asset whose actual path
differs from the rule-expected path (asset, rule, current path, expected path). It is read-only;
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
```

Reads the distinct failed objects from the audit log (bounded by `--limit`, grouped per object so a
flood of failures is not an unbounded scan) and re-organizes each. `--async` queues an organize
message per object instead of running inline. Exits non-zero if any inline re-organize fails again.

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
```

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
