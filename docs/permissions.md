[← Documentation index](index.md) · [Project README](../README.md)

# Permissions

Asset Pilot registers three permissions in Pimcore's native permission system during installation. Assign them to user roles via **Settings > Users / Roles > Permissions**.

| Permission | Key | Description |
|------------|-----|-------------|
| **View** | `asset_pilot_view` | View dashboard, rules, audit log, unused assets, search assets. All read-only endpoints. |
| **Operate** | `asset_pilot_operate` | Organize assets, lock/unlock, bulk tag, bulk set properties, delete/move unused assets. |
| **Admin** | `asset_pilot_admin` | Revert completed operations from the audit log. |

All API endpoints enforce permissions via `#[IsGranted(AssetPilotPermission::*)]` attributes. The Studio UI hides action buttons when the user lacks the required permission.

On top of these bundle-level permissions, the destructive operations (unused-asset delete and move, and audit revert) also enforce Pimcore's native per-asset workspace ACL: each asset is checked with `isAllowed('delete')` / `isAllowed('publish')` for the current user, so an Operate or Admin user still cannot act on an asset outside their workspace permissions. CLI runs (e.g. `asset-pilot:cleanup-unused`) are treated as a trusted system context and are not workspace-gated.

The `GET /permissions` endpoint returns the current user's permission set:

```json
{
    "view": true,
    "operate": true,
    "admin": false
}
```
