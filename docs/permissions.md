[← Documentation index](index.md) · [Project README](../README.md)

# Permissions

Asset Pilot registers three permissions in Pimcore's native permission system during installation. Assign them to user roles via **Settings > Users / Roles > Permissions**.

| Permission | Key | Description |
|------------|-----|-------------|
| **View** | `asset_pilot_view` | View dashboard, rules, audit log, unused assets, search assets, and most read-only endpoints. |
| **Operate** | `asset_pilot_operate` | Organize assets, lock/unlock, bulk set properties, delete/move unused assets, and other mutations. |
| **Admin** | `asset_pilot_admin` | Revert audit operations; list and undo integrity heals; merge duplicate groups; read global storage trends; recover operations; and retry dead observer deliveries. |

All API endpoints enforce permissions server-side via `#[IsGranted]`. Most reads require View and
most mutations require Operate; the Admin exceptions are listed above. Listing tags additionally
requires Pimcore's native `tags_assignment` permission, and bulk tag requires both that permission
and Operate. The Studio navigation carries `permission: 'asset_pilot_view'`, and individual action
buttons use the Studio SDK's `isAllowed('asset_pilot_operate' | 'asset_pilot_admin')`. Permission
checking follows Pimcore's mechanisms; there is no custom permission endpoint.

Bundle permissions do not broaden Pimcore workspaces. The SQL-backed asset listings (search, unused,
duplicate, quarantine, integrity history, empty folders) pass every emitted row through Pimcore's native
`isAllowed('view')`, which also honours workflow and permission-event denial, so a scoped actor sees only
assets they may view and never receives an exact result total that would let them count hidden assets.
Mutations re-check the required object, source asset, target folder, and referring-element permissions
before they are queued and again immediately before the write. Only the supervised System actor skips the
native per-row check; a Pimcore admin is still subject to workflow denial.

The audit log and the dashboard, metrics, and unused-storage aggregates are workspace-scoped by path: a
scoped user sees only operations whose asset or object is within their workspace, including via the recorded
historical from/to paths for elements that have since been deleted. System and admin see the full trail (an
admin is unbounded by workspace, which is Pimcore's own model). One residual is deliberate: a workflow or
permission-event `view` denial on a still-existing, in-workspace element is not additionally applied to that
element's historical audit metadata or to the cached aggregate counts. It never widens a workspace boundary.

HTTP requests and automatic events triggered by an authenticated request use that Pimcore user as
the actor. Messenger messages persist the actor type and user ID, and workers resolve the user again
so deactivation or workspace revocation takes effect before processing. Non-interactive CLI and
maintenance events use an explicit trusted system actor. Grant that global system authority only to
the documented supervised commands and consumers.
