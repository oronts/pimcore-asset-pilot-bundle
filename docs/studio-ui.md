[← Documentation index](index.md) · [Project README](../README.md)

# Studio UI

Asset Pilot integrates into Pimcore Studio as a Module Federation remote, registered under
Experience & E-commerce → Asset Pilot. It has a tab per feature (Dashboard, Rules, Operations, Audit
Log, Unused Assets, Duplicates, Integrity, Quarantine, Storage, Empty Folders, Drift, Asset
Management). The core tabs:

| Tab | Description |
|-----|-------------|
| **Dashboard** | Statistics overview (organized, pending, failed, skipped counts), a health panel (audit table, rule config, shared cache, async transport), class breakdown table, recent operations list |
| **Rules** | View all configured rules with priority, strategy, target path. Export the rule set as a portable artifact. An overlap panel warns about rules competing for the same assets. Detail modal with configuration and statistics. Preview modal to test a rule against a specific object ID. |
| **Operations** | Single object organize (with dry-run, async, and explain modes). Bulk organize by class with paginated preview and "Organize All" button. Replay failed operations (Operate permission). System status with refresh. |
| **Audit Log** | Full operation history with sorting. Filter by class, status, and rule name. CSV export. Revert individual operations. |
| **Unused Assets** | Confidence-scored unused asset list with color-coded badges. Filter by type, extensions, date range, folder, and confidence level. Bulk delete or move selected assets. Filter presets. |
| **Asset Management** | Search assets by filename/path, filter by type, folder, or Object ID. Lock/unlock assets. Bulk assign tags. Bulk set custom properties. Sortable columns with pagination. |

### Confidence Badges

Unused assets display color-coded confidence badges:

| Badge | Color | Meaning |
|-------|-------|---------|
| Definitely Unused | Green | Safe to clean up (>90 days, no references) |
| Probably Unused | Yellow | Review recommended (30-90 days) |
| Recently Uploaded | Red | Wait before action (<30 days) |
| Historically Used | Orange | Was previously organized — investigate |
| Protected | Gray | Locked asset, excluded from cleanup |

The day thresholds shown above are defaults, configurable via `confidence.recently_uploaded_days`
and `confidence.probably_unused_days` (see [Configuration](configuration.md)).

### Localization

The Studio UI ships with English and German translations. All UI strings use the `asset-pilot.*` i18n namespace.

### Permissions

Asset Pilot uses Pimcore's own permission system, not a custom one:

- The three permissions (`asset_pilot_view` / `asset_pilot_operate` / `asset_pilot_admin`) are
  registered as Pimcore `Permission\Definition`s by the installer and appear under Settings →
  Users/Roles (category "Asset Pilot"). Admins are allowed everything automatically.
- **Menu visibility** is gated by the nav item's `permission: 'asset_pilot_view'` — Studio hides the
  module for users without it.
- **Button-level gating** (operate/admin) uses the SDK's `isAllowed()` from `@pimcore/studio-ui-bundle/modules/auth`.
- **Enforcement** is server-side: every REST endpoint carries `#[IsGranted(AssetPilotPermission::*)]`
  (read = View, mutating = Operate, revert/merge = Admin).

The frontend calls the API with the studio session cookie (`credentials: 'same-origin'`) over
`getPrefix()` — no custom token handling.

> After `pimcore:bundle:install`, run `bin/console pimcore:cache:clear`. Studio caches the set of
> known permission keys (`USER_PERMISSIONS`); without clearing the Pimcore data cache the newly
> registered `asset_pilot_*` permissions are not recognised and every endpoint returns 403. Symfony's
> `cache:clear` does not clear the Pimcore data cache.

### Building the frontend

The bundle ships the UI source under `assets/studio` but not the compiled bundle (`public/studio/build`
is gitignored). Build it against the Studio version your project runs:

```bash
cd assets/studio
# set @pimcore/studio-ui-bundle in package.json to match your installed pimcore/studio-ui-bundle
npm install
npm run build           # outputs public/studio/build/<id> (entrypoints.json + remoteEntry.js)
```

Then publish the bundle assets so the web server serves them, and clear caches:

```bash
bin/console assets:install          # hard-copy (use this; a symlink target outside the web root is not served)
bin/console cache:clear
```

The module-registration API is shared across the studio 0.15 / 1.x / 2025.x lines, so the same source
builds against any of them; only the npm SDK version needs to match the runtime.
