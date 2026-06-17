# Asset Pilot Documentation

Reference documentation for the Pimcore 12 Asset Pilot bundle. For a project overview, features, and
quick start, see the [project README](../README.md).

## Getting started

- [Installation](installation.md): require the package, enable the bundle, install the database
  table and permissions, configure Messenger and Lock, build the Studio UI.
- [Configuration](configuration.md): the full `oronts_asset_pilot` configuration tree, rule
  reference, and per-rule options.
- [Configuration Scenarios](scenarios.md): worked recipes for e-commerce, category hierarchies,
  multi-class setups, move strategies, sync mode, asset protection, and date-based organization.

## Reference

- [Commands](commands.md): every `asset-pilot:*` console command, including organize, validate,
  debug, status, audit, and unused-asset cleanup, plus cron examples.
- [REST API](rest-api.md): the Studio backend endpoints under `/pimcore-studio/api/asset-pilot`.
- [Path Templates](path-templates.md): Twig path templates, context variables, and the custom
  filters and functions available in target paths.
- [Conditions](conditions.md): the Symfony ExpressionLanguage condition syntax and built-in
  functions.
- [Permissions](permissions.md): the three permission levels and how they gate each operation.
- [Studio UI](studio-ui.md): the dashboard tabs, confidence badges, and localization.
- [Architecture](architecture.md): the rule-engine pipeline, idempotency and loop prevention, and
  the database schema.

## Extending & contributing

- [Extending](extending.md): every extension point. Custom filters, strategies, path resolvers,
  condition evaluators, naming strategies, Twig and ExpressionLanguage hooks, context providers, and
  the event surface.
- [Testing](testing.md): how to run the suite and how the kernel-free tests are structured.
