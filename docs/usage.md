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
3. Every move is written to the audit log with the source path, target path, rule, trigger, status,
   and duration.
4. Re-saving the same object does not move an asset that is already at its target, and the loop
   guard plus Messenger deduplication keep concurrent saves from moving the same asset twice. See
   [Architecture](architecture.md#idempotency--loop-prevention).

Uploading an asset that a DataObject already references also re-triggers organization for that
object, so a late upload still lands in the right folder.

## Organizing on demand

You do not have to wait for a save. Run a rule set across existing objects from the CLI:

```bash
# Preview every move for the Product class without touching a file
bin/console asset-pilot:organize --class=Product --dry-run

# Run it for real, asynchronously, in batches of 100
bin/console asset-pilot:organize --class=Product --async --batch-size=100

# Organize a single object
bin/console asset-pilot:organize --object-id=1234
```

The same preview and run are available from the Operations tab in the Studio UI and over the
[REST API](rest-api.md#operations). The full command surface is in [Commands](commands.md).

## Previewing before you move

Three ways to see what a rule would do before committing:

- `asset-pilot:organize --dry-run` (add `-v` for the full per-asset evaluation trace).
- `asset-pilot:debug-rule --object-id=1234` for a step-by-step explanation of why each rule matched
  or was skipped.
- The Rules tab "Preview" in the Studio UI, which resolves the moves for one object against a single
  rule and offers "Apply Now" for exactly that rule.

## Audit, history, and revert

Every move is recorded. Inspect and manage history with:

```bash
# Recent operations, with class / status / rule filters
bin/console asset-pilot:audit --class=Product --status=completed

# Prune entries older than the configured retention window
bin/console asset-pilot:audit --cleanup
```

In the Studio UI the Audit Log tab adds CSV export and a per-row **Revert** that moves an asset back
to its original path. Revert re-verifies state and is loop-guarded, so it is not undone by the async
pipeline. Revert requires the `asset_pilot_admin` permission (see [Permissions](permissions.md)).

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
# Preview what would be removed (never deletes on a dry run)
bin/console asset-pilot:cleanup-unused --dry-run

# Move unused assets into an archive folder instead of deleting
bin/console asset-pilot:cleanup-unused --action=move --move-to=/Archive/Unused
```

Bulk delete and bulk move re-check that each asset is still unreferenced at the moment of the action
and honor per-asset Pimcore workspace permissions, so a file that became referenced after the listing
is skipped rather than removed.

## Protecting assets from organization

Two ways to keep an asset where it is:

- **Lock a single asset**: set the `asset_pilot_locked` property (the Studio UI lock button does
  this). Locked assets are skipped by both organization and cleanup and show the `protected`
  confidence level. The property name is configurable, see
  [Configuration](configuration.md).
- **Exclude a whole folder tree**: list it under `protection.exclude_folders`. Nothing inside is
  ever moved.

## The Studio UI

Everything above is also available in Pimcore Studio across six tabs (Dashboard, Rules, Operations,
Audit Log, Unused Assets, Asset Management). See [Studio UI](studio-ui.md).
