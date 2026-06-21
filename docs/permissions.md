[← Documentation index](index.md) · [Project README](../README.md)

# Permissions

Asset Pilot registers three permissions in Pimcore's native permission system during installation. Assign them to user roles via **Settings > Users / Roles > Permissions**.

| Permission | Key | Description |
|------------|-----|-------------|
| **View** | `asset_pilot_view` | View dashboard, rules, audit log, unused assets, search assets. All read-only endpoints. |
| **Operate** | `asset_pilot_operate` | Organize assets, lock/unlock, bulk tag, bulk set properties, delete/move unused assets. |
| **Admin** | `asset_pilot_admin` | Revert completed operations from the audit log; merge duplicate groups (`POST /duplicates/merge`). |

All API endpoints enforce permissions server-side via `#[IsGranted(AssetPilotPermission::*)]` attributes (read = View, mutating = Operate, revert/merge = Admin). The Studio UI never fetches a permission set: the navigation entry carries `permission: 'asset_pilot_view'` so Studio hides the module itself, and individual action buttons are gated with the Studio SDK's `isAllowed('asset_pilot_operate' | 'asset_pilot_admin')` from `@pimcore/studio-ui-bundle/modules/auth` (which grants admins everything). Permission checking follows Pimcore's own mechanisms; there is no custom permission endpoint.

On top of these bundle-level permissions, the destructive operations (unused-asset delete and move, quarantine and quarantine restore, empty-folder delete, audit revert, and duplicate-merge copy disposition) also enforce Pimcore's native per-asset workspace ACL: each asset is checked with `isAllowed('delete')` / `isAllowed('publish')` for the current user, so an Operate or Admin user still cannot act on an asset outside their workspace permissions. CLI runs (e.g. `asset-pilot:cleanup-unused`) are treated as a trusted system context and are not workspace-gated.
