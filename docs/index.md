# Asset Pilot Documentation

Documentation for the Pimcore 12 Asset Pilot bundle. For a project overview and quick start, see the
[project README](../README.md).

## Getting started

- [Installation](installation.md): require the package, enable the bundle, install the database table
  and permissions, configure Messenger and Lock, build the Studio UI.
- [Usage](usage.md): how the bundle behaves day to day, previewing, audit and revert, unused-asset
  cleanup, and protecting assets.
- [Configuration](configuration.md): the full `oronts_asset_pilot` tree, rule reference, and per-rule
  options.
- [Scenarios](scenarios.md): worked rule recipes for e-commerce, category hierarchies, multi-class
  setups, move strategies, sync mode, asset protection, and date-based organization.

## Reference

- [Reference](reference.md): one-page lookup for every config key, command, endpoint, event, enum,
  permission, tag, and service.
- [Commands](commands.md): every `asset-pilot:*` console command, with cron examples.
- [REST API](rest-api.md): the Studio backend endpoints under `/pimcore-studio/api/asset-pilot`.
- [Path Templates](path-templates.md): Twig templates, context variables, custom filters and functions.
- [Conditions](conditions.md): ExpressionLanguage condition syntax and built-in functions.
- [Permissions](permissions.md): the three permission levels and what they gate.
- [Studio UI](studio-ui.md): dashboard tabs, confidence badges, and localization.
- [Architecture](architecture.md): the rule-engine pipeline, idempotency and loop prevention, and the
  database schema.

## Extending & overriding

- [Developer Experience](dx.md): the map of every seam and the local workflow. Start here.
- [Extending](extending.md): add behavior, custom filters, strategies, path resolvers, condition
  evaluators, naming strategies, Twig and ExpressionLanguage hooks, programmatic rules, and events.
- [Overriding](overriding.md): change the bundle's own behavior by replacing or decorating its core
  services and defaults.
- [Testing](testing.md): how to run the suite and how the kernel-free tests are structured.
