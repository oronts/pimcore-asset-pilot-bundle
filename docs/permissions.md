[← Documentation index](index.md) · [Project README](../README.md)

# Permissions

Asset Pilot registers three permissions in Pimcore's native permission system during installation. Assign them to user roles via **Settings > Users / Roles > Permissions**.

| Permission | Key | Description |
|------------|-----|-------------|
| **View** | `asset_pilot_view` | View dashboard, rules, audit log, unused assets, search assets. All read-only endpoints. |
| **Operate** | `asset_pilot_operate` | Organize assets, lock/unlock, bulk tag, bulk set properties, delete/move unused assets. |
| **Admin** | `asset_pilot_admin` | Revert completed operations from the audit log. |

All API endpoints enforce permissions via `#[IsGranted(AssetPilotPermission::*)]` attributes. The Studio UI hides action buttons when the user lacks the required permission.

The `GET /permissions` endpoint returns the current user's permission set:

```json
{
    "view": true,
    "operate": true,
    "admin": false
}
```
