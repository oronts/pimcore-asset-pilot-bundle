# Contributing to Asset Pilot

Thanks for considering a contribution. This bundle powers asset organization on production
Pimcore platforms, so the bar is correctness and a tight, reviewable diff. The notes below get you
set up and tell you what a mergeable change looks like.

## Reporting issues

Open an issue with:

- the Pimcore, PHP, and bundle versions,
- the relevant `oronts_asset_pilot` config (rules, strategies, async settings),
- what you expected and what happened, with the smallest reproduction you can manage.

For anything touching a destructive path (delete, move, quarantine, merge, revert), include the
audit-log rows or the `asset-pilot:audit` output for the affected asset.

## Development setup

```bash
composer install
```

The Studio UI ships as source (a Module Federation remote), not prebuilt. To work on it:

```bash
npm --prefix assets/studio ci
npm --prefix assets/studio run build      # emits the remote into public/studio/build
```

## Quality gates

Every change must pass all of these before it is reviewed. They are wired as composer scripts:

```bash
composer test     # PHPUnit, kernel-free unit suite
composer stan     # PHPStan (level 5)
composer cs       # php-cs-fixer (dry-run); composer cs-fix to apply
```

For the Studio UI, also run the type check:

```bash
npm --prefix assets/studio run check-types
```

The `en` and `de` translation catalogs (`assets/studio/js/src/i18n/`) must stay in parity: every key
present in one exists in the other.

## Coding standards

- PHP `>=8.4`, Symfony `^7`, Pimcore `^12`. PHP 8.4 syntax is welcome; this bundle is Pimcore 12 only.
- Follow the existing style; php-cs-fixer is the source of truth.
- Keep diffs surgical. Change only what the task needs, and clean up orphans your change creates.
- Do not override Pimcore core and do not duplicate fields on data objects.
- Do not add consumer-specific logic to this generic bundle. Domain context belongs behind an
  extension seam (see below).

## Non-negotiables

- The move pipeline must stay loop-safe under async. Any code that saves an asset or object from
  inside the pipeline uses `LoopGuard` correctly.
- Every destructive operation re-verifies state and is guarded by a test.
- Every REST endpoint carries `#[IsGranted(AssetPilotPermission::*)]`: read = View, mutating =
  Operate, revert and merge = Admin.
- Never interpolate untrusted input into SQL identifiers. Bind values and whitelist columns.

## Tests

We work test-first. New behavior and bug fixes ship with a test that fails before the change and
passes after. The suite is kernel-free: static-coupled code is exercised through protected method
seams overridden in anonymous subclasses. See [docs/testing.md](docs/testing.md).

## Extending instead of forking

Most additions belong behind an existing seam rather than a core edit: rule actions, condition
functions, path-template context and filters, naming and conflict strategies, integrity checkers,
duplicate-merge and zip strategies, notifiers, health checks, and rule providers. See
[docs/extending.md](docs/extending.md) and [docs/overriding.md](docs/overriding.md). If you find
yourself editing the core to add domain logic, that is usually a sign a seam is the better path; if
the seam is missing, propose it in an issue first.

## Pull requests

- One focused change per PR, with all gates green.
- A clear description of the what and the why; link the issue.
- Update the relevant `docs/` pages when behavior, config keys, commands, endpoints, or seams change.

## License

The bundle is licensed under AGPL-3.0-or-later. By contributing, you agree your contribution is
licensed under the same terms.

## Contact

Questions about contributing or commercial support: office@oronts.com.
