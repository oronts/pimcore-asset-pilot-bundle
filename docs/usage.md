[Docs index](index.md)

# Using Asset Pilot

How the bundle behaves day to day once it is installed and at least one rule is configured. For the
rule syntax see [Configuration](configuration.md); for ready-made rule recipes see
[Scenarios](scenarios.md).

## The organization lifecycle

Asset Pilot is event-driven. Once a rule matches a class, the pipeline runs on its own:

1. A DataObject is saved (`postAdd` / `postUpdate`). The bundle reads the asset fields named by the
   matching rules, evaluates each rule's condition and filters, and resolves a target path from the
   rule's Twig template.
2. Each asset that matches is moved to its target folder. By default the move is dispatched to
   Symfony Messenger and handled by a worker; set `async.enabled: false` for synchronous moves.
3. Before an actual move or revert, the mandatory journal transaction stores the exact mutation
   intent and prepared observer deliveries. If that write fails, the mutation does not start. After
   the save, the persisted asset is reloaded and classified as committed, not committed, or requiring
   recovery. Pre-mutation skip rows remain best-effort audit entries because no asset state changes.
4. Re-saving the same object does not move an asset that is already at its target. The loop guard,
   durable run items, and per-asset locks keep concurrent saves from moving the same asset twice. See
   [Architecture](architecture.md#idempotency--loop-prevention).

Uploading an asset that a DataObject already references also re-triggers organization for that
object, so a late upload still lands in the right folder.

Rule actions and durable operation events are delivered from the database outbox after a committed
outcome. Consequently, both the `asset_pilot` and `pimcore_maintenance` consumers remain required
when `async.enabled: false`; that setting only makes organization itself run in the request process.

Inspect stale journal entries and apply only the signed, reviewed classifications with:

```bash
bin/console asset-pilot:recover-operations --limit=100
bin/console asset-pilot:recover-operations --limit=100 --apply --plan-token='v1...'
```

Recovery restores the recorded actor, rechecks publish access, acquires the shared asset lock, and
classifies persisted state. It never repeats the original move or revert.

Inspect and requeue exhausted observer deliveries with the same signed review contract:

```bash
bin/console asset-pilot:retry-deliveries --limit=100
bin/console asset-pilot:retry-deliveries --limit=100 --apply --plan-token='v1...'
```

The durable delivery keeps its original operation and actor. Requeueing does not repeat the asset
move or revert.

## Organizing on demand

You do not have to wait for a save. Run a rule set across existing objects from the CLI:

```bash
# Preview every move for the Product class without touching a file
bin/console asset-pilot:organize --class=Product --batch-size=100

# Queue the exact reviewed selection with the token printed by the preview
bin/console asset-pilot:organize --class=Product --batch-size=100 --apply --plan-token='v1...' --async

# Preview, then organize one exact object selection
bin/console asset-pilot:organize --object-id=1234
bin/console asset-pilot:organize --object-id=1234 --apply --plan-token='v1...'
```

The same preview and run are available from the Operations tab in the Studio UI and over the
[REST API](rest-api.md#operations). The full command surface is in [Commands](commands.md).

## Previewing before you move

Three ways to see what a rule would do before committing:

- `asset-pilot:organize --object-id=1234` (add `-v` for the full per-asset evaluation trace).
- `asset-pilot:debug-rule --object-id=1234` for a step-by-step explanation of why each rule matched
  or was skipped.
- The Rules tab "Preview" in the Studio UI, which resolves the moves for one object against a single
  rule and offers "Apply Now" for exactly that rule.

## Audit, history, and revert

Inspect and manage the recorded
history with:

```bash
# Recent operations, with class / status / rule filters
bin/console asset-pilot:audit --class=Product --status=completed

# Prune entries older than the configured retention window
bin/console asset-pilot:audit --cleanup
```

In the Studio UI the Audit Log tab adds CSV export and a per-row **Revert** that moves an asset back
to its original path. Revert re-verifies state and is loop-guarded, so it is not undone by the async
pipeline. Revert requires the `asset_pilot_admin` permission (see [Permissions](permissions.md)). A
revert records the acting user in the audit row's `user_id`. User-triggered API and Studio requests
carry that Pimcore user through Messenger, so their queued operations retain the same `user_id`.
Automatic listeners, maintenance, and CLI commands run as the system actor and leave `user_id` null;
`rule_name` still identifies the applied rule.

## Finding and cleaning up unused assets

Asset Pilot flags assets that no DataObject or Document references and scores how confident it is:

| Confidence | Meaning |
|------------|---------|
| `definitely_unused` | No reference and old enough to be safe to remove |
| `probably_unused` | No reference, modified within the medium window |
| `recently_uploaded` | No reference yet, but uploaded recently |
| `historically_used` | No current reference, but moved by a rule before |
| `protected` | Locked, so excluded from cleanup |

Review them in the Unused Assets tab (filter by type, extension, date range, folder, and confidence)
or from the CLI:

```bash
# Preview what would be removed; cleanup previews unless --apply is present
bin/console asset-pilot:cleanup-unused --all

# Apply the exact reviewed move with the token printed by the preview
bin/console asset-pilot:cleanup-unused --all --action=move --move-to=/Archive/Unused --apply --plan-token='v1...'
```

Bulk delete and bulk move re-check that each asset is still unreferenced at the moment of the action
and honor per-asset Pimcore workspace permissions, so a file that became referenced after the listing
is skipped rather than removed.

Other destructive maintenance commands use the same preview-token-apply contract:

```bash
# Each preview prints a signed, single-use plan token
bin/console asset-pilot:merge-duplicates --checksum=<hash> --canonical=123 --strategy=delete
bin/console asset-pilot:sweep-empty-folders --folder=/Products
bin/console asset-pilot:quarantine-purge --grace-days=30

# Apply with identical selectors before the token expires
bin/console asset-pilot:merge-duplicates --checksum=<hash> --canonical=123 --strategy=delete --apply --plan-token='v1...'
bin/console asset-pilot:sweep-empty-folders --folder=/Products --apply --plan-token='v1...'
bin/console asset-pilot:quarantine-purge --grace-days=30 --apply --plan-token='v1...'
```

Plans bind the system actor, selectors, effective configuration, exact sorted targets, and live
fingerprints. Selection or state drift, expiry, and token reuse are rejected before mutation. A
persisted duplicate merge resumes with `--run-id --apply` and does not require another token.

## Protecting assets from organization

Two ways to keep an asset where it is:

- **Lock a single asset**: set the `asset_pilot_locked` property (the Studio UI lock button does
  this). Locked assets are skipped by both organization and cleanup and show the `protected`
  confidence level. The property name is configurable, see
  [Configuration](configuration.md).
- **Exclude a whole folder tree**: list it under `protection.exclude_folders`. Nothing inside is
  ever moved.

## The Studio UI

Pimcore Studio exposes twelve focused tabs: Dashboard, Rules, Operations, Audit Log, Unused Assets,
Duplicates, Integrity, Quarantine, Storage, Empty Folders, Drift, and Asset Management. The UI covers
the interactive review and mutation workflows; additional maintenance and extension surfaces remain
CLI, REST, or service APIs. Integrity keeps broken-asset detection separate from the admin-only
reversible-heal history. Its eligibility indicator is a lightweight metadata check; Undo repeats the
authoritative content and state checks under the asset lock before restoring anything. See [Studio
UI](studio-ui.md).
