<p align="center">
  <a href="https://oronts.com">
    <img src="docs/images/asset-pilot-logo.svg" alt="Asset Pilot — by Oronts" width="560">
  </a>
</p>

<p align="center">
  <strong><code>oronts/asset-pilot-bundle</code>: intelligent rule-based asset organization for Pimcore 12</strong>
</p>

<p align="center">
  <a href="#license"><img src="https://img.shields.io/badge/License-AGPL--3.0-blue.svg" alt="License"></a>
  <a href="https://www.pimcore.com/"><img src="https://img.shields.io/badge/pimcore-%5E12.3.11-purple" alt="Pimcore version"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/php-%3E%3D8.4-blue" alt="PHP version"></a>
  <a href="https://symfony.com/"><img src="https://img.shields.io/badge/symfony-%5E7.3-black" alt="Symfony version"></a>
</p>

<p align="center">
  <a href="#quick-start">Quick start</a> &bull;
  <a href="#example">Example</a> &bull;
  <a href="#documentation">Documentation</a> &bull;
  <a href="docs/index.md">Full docs</a>
</p>

---

<p align="center">
  <img src="docs/images/dashboard.png" alt="Asset Pilot — Studio UI dashboard" width="800">
</p>

Asset Pilot automates how Pimcore assets are filed. When a DataObject is saved, it evaluates the
object's asset fields against a priority-ordered rule set, resolves a target path from a Twig template,
and moves the files into a structured folder hierarchy, handling localized fields, async processing,
a mandatory recoverable operation journal, durable observer delivery, and a Studio UI dashboard.

## Features

- **Rule engine**: priority-ordered rules with class and field targeting, ExpressionLanguage
  conditions, and type/size/extension filters.
- **Twig target paths**: full Twig templates with custom filters and functions, and per-locale paths
  for localized fields.
- **Async processing**: Symfony Messenger, renewable locks, actor context, coalescing stale jobs,
  and already-at-target checks protect the move pipeline across workers.
- **Audit & revert**: completed and attempted operations carry source, target, duration, actor, and
  trigger data in the mandatory audit journal; CSV export and guarded revert are built in.
- **Recovery & durable delivery**: move/revert intent is journaled before mutation; stale operations
  are safely classified without repeating the mutation, while rule actions and operation events use
  a database-backed outbox with retries, leases, dead-letter health, and exact actor restoration.
- **Unused-asset cleanup**: confidence-scored detection with bulk delete or archive, signed
  preview/apply plans for REST mutations, and per-asset or per-folder protection.
- **Dependency safety**: an indexed, revision-fenced projection returns explicit safe, referenced,
  or unknown verdicts; incomplete bootstrap and dirty sources block destructive operations.
- **Integrity healing**: bounded broken-binary scans, previewable version rollback, and a separate
  admin history that exposes Undo only while the recorded heal remains safely reversible.
- **Studio UI**: a tabbed React dashboard (Dashboard, Rules, Operations, Audit, Unused, Duplicates,
  Integrity, Quarantine, Storage, Empty Folders, Drift, Asset Management) mounted in Pimcore Studio
  via Module Federation.
- **Built to extend**: documented service tags, replaceable aliases, and typed events cover the
  supported extension surfaces. See [Extending](docs/extending.md) and [Overriding](docs/overriding.md).

## Quick Start

```bash
composer require oronts/asset-pilot-bundle
```

The latest published stable is 1.1.x. The 2.0 feature set documented here is the next release and is
not yet on Packagist, so `composer require oronts/asset-pilot-bundle` installs the current 1.1.x
stable line until `v2.0.0` is tagged and published.

Asset Pilot requires an installed Pimcore Studio. A bare Pimcore Classic skeleton must install and
configure Generic Execution Engine, Generic Data Index, Studio Backend, and Studio UI first; see the
[installation guide](docs/installation.md#fresh-installation) for the verified order.

Enable the bundle in `config/bundles.php`:

```php
return [
    // ...
    Oronts\AssetPilotBundle\OrontsAssetPilotBundle::class => ['all' => true],
];
```

Install the database tables and permissions. The Composer package already contains a verified
prebuilt Studio remote:

```bash
bin/console pimcore:bundle:install OrontsAssetPilotBundle
bin/console assets:install
bin/console pimcore:cache:clear
bin/console asset-pilot:rebuild-dependency-projection
bin/console asset-pilot:health
```

Rerun the bounded projection rebuild until it reports `ready` before enabling destructive cleanup.

Full setup, including both required supervised Messenger consumers, failure transport, Lock, upgrades,
and safe uninstall, is in
[docs/installation.md](docs/installation.md).

Operators can inspect stale mutation state with `asset-pilot:recover-operations` and apply only its
signed reviewed classification. Recovery never repeats the original move or revert.
Dead observer deliveries use the same preview/apply discipline through
`asset-pilot:retry-deliveries`; retry preserves the operation and initiating actor.

## Example

Organize product images into a folder named by item number:

```yaml
oronts_asset_pilot:
    rules:
        product_images:
            class: Product
            fields: [images, galleryImages]
            condition: 'object.getItemNumber() != null'
            target_path: '/Products/{{ object.getItemNumber() }}/Images'
            strategy: always
            priority: 100
            filters:
                types: [image]
                extensions: [jpg, png, webp]
```

Preview the moves, then apply that exact reviewed plan before its token expires:

```bash
bin/console asset-pilot:organize --class=Product --batch-size=100
bin/console asset-pilot:organize --class=Product --batch-size=100 --apply --plan-token='v1...' --async
```

More recipes (category hierarchies, multi-class setups, move strategies, asset protection, date-based
organization) are in [docs/scenarios.md](docs/scenarios.md).

## Documentation

Everything lives in **[docs/](docs/index.md)**.

- **Getting started**: [Installation](docs/installation.md) &middot; [Usage](docs/usage.md) &middot; [Configuration](docs/configuration.md) &middot; [Scenarios](docs/scenarios.md)
- **Releases**: [Changelog](CHANGELOG.md) &middot; [Upgrade guide](UPGRADING.md)
- **Reference**: [Reference](docs/reference.md) &middot; [Commands](docs/commands.md) &middot; [REST API](docs/rest-api.md) &middot; [Path Templates](docs/path-templates.md) &middot; [Conditions](docs/conditions.md) &middot; [Permissions](docs/permissions.md) &middot; [Studio UI](docs/studio-ui.md) &middot; [Architecture](docs/architecture.md)
- **Extending & overriding**: [Developer Experience](docs/dx.md) &middot; [Extending](docs/extending.md) &middot; [Overriding](docs/overriding.md) &middot; [Testing](docs/testing.md)

## Requirements

| Dependency | Version |
|------------|---------|
| PHP | >= 8.4 |
| Pimcore | ^12.3.11 |
| Pimcore Studio Backend | ^2025.4.7 |
| Pimcore Studio UI | ^2025.4.8 |
| Symfony components | ^7.3 |
| Symfony Messenger | ^7.3 |
| Symfony Lock | ^7.3 |

## Contributing

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for the dev setup, the
quality gates, and what a mergeable change looks like.

## License

Licensed under the [GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0-or-later). Pimcore and
Studio dependencies have their own licenses. Review the licenses for your distribution and service
model; AGPL section 13 applies when users interact remotely with a modified covered version.

---

## Built and maintained by Oronts

<p align="center">
  <a href="https://oronts.com">
    <img src="https://oronts.com/_next/image?url=%2Fimages%2Flogo%2FLogo-white.png&w=256&q=75" alt="Oronts" width="200">
  </a>
</p>

Asset Pilot is built and maintained by **Oronts**, an AI-first software company in Munich. We design and
run commerce platforms, PIM and DAM systems, and the data automation around them for mid-market and
enterprise teams, with Pimcore experience spanning versions 10, 11, and 12. This bundle is the
asset-filing engine we use on client platforms and release under AGPL-3.0-or-later. Validate it in a
representative Pimcore environment before rollout. When you want it shaped to your data model or
backed by an SLA, that is the work we do.

**Where we help:**

- **Production rollout.** Installation, rule design for your class and field model, async and multi-pod
  tuning, and migrating a messy asset tree into a clean, predictable structure without downtime.
- **Custom extensions.** Merge strategies, integrity checkers, condition functions, path-template
  context, notifiers, and rule providers, each behind a documented seam so your logic stays out of the
  core and survives upgrades.
- **The platform around it.** Pimcore and DataHub integrations, PIM and DAM rollouts, headless commerce,
  search on OpenSearch or MeiliSearch, and AI-assisted enrichment and classification.
- **Support and SLAs.** Code review, version upgrades, performance work, and on-call cover for
  business-critical Pimcore systems.

If your team files assets by hand, fights duplicates, or runs a DAM nobody trusts, we fix the workflow
and the platform underneath it. Tell us what you run and we will scope it.

**Contact:** office@oronts.com &middot; [oronts.com](https://oronts.com)

**Author:** Refaat Al Ktifan (Refaat@alktifan.com)
