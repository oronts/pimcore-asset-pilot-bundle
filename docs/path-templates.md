[← Documentation index](index.md) · [Project README](../README.md)

# Path Templates

Target paths use Twig syntax. The resolver provides these context variables:

| Variable | Type | Description |
|----------|------|-------------|
| `object` | `AbstractObject` | The DataObject being saved |
| `asset` | `Asset` | The asset being organized |
| `locale` | `?string` | Locale code for localized fields (`en`, `de`, etc.) or `null` |
| `date` | `DateTimeImmutable` | Current date/time |
| `className` | `string` | DataObject class name |

Consumers can add more variables by tagging a `ContextProviderInterface` service (`oronts_asset_pilot.context_provider`); the core variables above always take precedence over provider keys. See [Extending](extending.md).

You can call any method on the `object` and `asset` variables directly in the template. Empty path segments are dropped. If a template renders to nothing usable (empty, or all `unknown`) it falls back to `/Assets/<object key>`, never the asset root, and a template whose accessor throws at render time fails closed with a `PathResolutionException` so the asset is not misfiled to a generic destination.

> **Trust boundary.** Path templates are trusted deployment code, not end-user input. The Twig
> environment is not sandboxed and `object` and `asset` are live Pimcore models, so a template can
> invoke any method on them. Restrict who may edit path templates to the operators who are already
> allowed to deploy code.

### Custom Filters

| Filter | Usage | Description |
|--------|-------|-------------|
| `safe_key` | `{{ value\|safe_key }}` | Replace any char that is not a letter, digit, `_`, `-`, or `.` with `-`; empty input becomes `unknown` |
| `pluck` | `{{ items\|pluck('key') }}` | Extract a property from each item in the list: object entries via getter, method, or public property; array entries by key |
| `first_of` | `{{ items\|first_of('key', 'default') }}` | Property from the first item only, which must be an object (read via its getter or method); a non-object first entry, e.g. an associative array, yields the fallback. Optional second arg sets the fallback (default `'unknown'`) |
| `slug` | `{{ value\|slug }}` | URL-safe lowercase slug |
| `fallback` | `{{ value\|fallback('default') }}` | Return fallback when value is empty/null |
| `trim_path` | `{{ value\|trim_path }}` | Strip leading/trailing slashes |

### Custom Functions

| Function | Usage | Description |
|----------|-------|-------------|
| `coalesce` | `{{ coalesce(a, b, c) }}` | First non-null, non-empty value |
| `prop` | `{{ prop(obj, 'getSku', arg1) }}` | Call a read accessor (`get`/`is`/`has`) on an object; other methods return null |
| `rel` | `{{ rel(object, 'categories', 0) }}` | Safely access a relation by index |
| `has_relation` | `{% if has_relation(object, 'categories') %}` | Check if relation has items |

### Path Template Examples

Each example is a standalone `target_path` for one rule. They are shown in separate blocks because a
single YAML document cannot repeat the `target_path` key.

```yaml
# Simple flat structure
target_path: '/Products/{{ object.getItemNumber() }}/Images'
```

```yaml
# Category hierarchy
target_path: '/Products/{{ object.getCategories()|first_of("key", "Uncategorized") }}/{{ object.getItemNumber() }}/Images'
```

```yaml
# Locale-aware paths for localized fields
target_path: '/Products/{{ object.getItemNumber() }}/Documents{{ locale ? "/" ~ locale : "" }}'
```

```yaml
# Date-based organization
target_path: '/Uploads/{{ date.format("Y/m") }}/{{ className }}'
```

```yaml
# Conditional logic with Twig
target_path: '{% if has_relation(object, "categories") %}/Products/{{ object.getCategories()|first_of("key") }}{% else %}/Products/Uncategorized{% endif %}/Assets'
```

```yaml
# Joined relation path
target_path: '/Products/{{ object.getCategories()|pluck("key")|join("/") }}/Media'
```

```yaml
# Coalesce multiple possible identifiers
target_path: '/Products/{{ coalesce(prop(object, "getItemNumber"), prop(object, "getSku"), object.getKey()) }}/Assets'
```

```yaml
# Fallback with slug
target_path: '/{{ className }}/{{ object.getName()|slug|fallback("unnamed") }}'
```
