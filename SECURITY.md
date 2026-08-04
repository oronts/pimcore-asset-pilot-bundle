# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.1.x | yes |
| < 1.1 | no |

1.1.x is the current published stable line on Packagist. The 2.0 line is in preparation and is not
yet published; when `v2.0.0` is tagged and released it becomes the supported line and this table is
updated in the same release operation.

Supported releases receive dependency and security fixes. Release CI runs locked Composer and npm
audits. The npm audit runs through `assets/studio/scripts/audit-allowlist.mjs`, which fails the build on
any advisory at low severity or above, except a small, documented, expiry-bound allowlist of advisories
deferred to the coordinated Pimcore Studio 2026.1 host upgrade (listed below). Any other upstream
advisory fails the build until it has a documented reachability assessment and mitigation.

### Known upstream advisories

The locked npm graph reports advisories in transitive dependencies that are not reachable from the
shipped Studio remote and whose fixes ship only in the breaking Pimcore Studio 2026.1 line. They are
deferred to the coordinated host-stack upgrade and allowlisted in `audit-allowlist.mjs` (review by
2026-11-01):

- `react-router` / `react-router-dom` (`GHSA-wrjc-x8rr-h8h6`, `GHSA-337j-9hxr-rhxg`,
  `GHSA-jjmj-jmhj-qwj2`; Moderate): transitive-only through `@pimcore/studio-ui-bundle` (Studio 2025.4),
  which the Asset Pilot remote consumes as a Module Federation shared singleton, so the host application
  provides react-router at runtime and no react-router code is bundled into or shipped by this remote.
  The SSR hydration advisory does not apply to the client-side Studio SPA, which performs no
  server-side rendering.
- `undici` (`GHSA-4cwx-7wf7-3272`, High; `GHSA-8xcm-r25x-g524`, `GHSA-m8rv-5g2x-5cg5`,
  `GHSA-jr45-8vmc-qm54`, `GHSA-v3r7-h72x-cjcm`, Moderate): transitive-only through the `@module-federation`
  build plugin. undici is a Node HTTP client used at build time and is never bundled into or shipped by
  the browser remote, so these server-side HTTP advisories are not reachable in the shipped artifact.

The `brace-expansion`, `fast-uri`, `dompurify`, and `postcss` advisories were resolved by refreshing the
lockfile.

## Authority model

Asset Pilot permissions do not replace Pimcore workspaces. Interactive operations must satisfy the
bundle permission and the relevant object, source asset, target folder, and referring-element
workspace permissions. Queued work carries actor context and revalidates it in the worker. Automatic
listeners and trusted CLI jobs run as an explicit system actor and should be enabled only where that
global authority is intended.

Treat Asset Pilot View as operationally sensitive even though read models and audit/run history are
workspace-scoped. Admin-only global aggregates, rule configuration, paths, and operational metadata
still belong with repository operators. Use a shared lock/cache backend, supervise both documented
workers, alert on queue failures, and keep destructive cleanup preview-first with current backups.

Rule Twig templates and ExpressionLanguage conditions are trusted deployment code because they
receive live Pimcore models. Do not allow untrusted users to edit them.

## Reporting a vulnerability

Do not open a public issue for security problems. Email **office@oronts.com** with:

- a description of the issue and its impact,
- the affected bundle, Pimcore, Studio, PHP, database, and worker versions,
- the smallest reproducible configuration or request,
- whether the issue crosses a Pimcore workspace or affects move, delete, quarantine, merge, heal,
  revert, archive, or queued processing.

We will acknowledge the report, validate reachability, coordinate a fix, and agree on a disclosure
timeline. Preserve logs and audit/run identifiers, but remove secrets and personal data.
