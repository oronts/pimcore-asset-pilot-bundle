# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 2.0.x | yes |
| 1.x | no |

Supported releases receive dependency and security fixes. Release CI runs locked Composer and npm
audits without ignored advisories. A remaining upstream advisory must have a documented reachability
assessment and mitigation before release.

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
