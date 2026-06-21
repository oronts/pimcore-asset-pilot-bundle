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

You can call any method on the `object` and `asset` variables directly in the template. The resolver handles null values gracefully and falls back to `'unknown'` for empty segments.

### Custom Filters

| Filter | Usage | Description |
|--------|-------|-------------|
| `safe_key` | `{{ value\|safe_key }}` | Replace any char that is not a letter, digit, `_`, `-`, or `.` with `-`; empty input becomes `unknown` |
| `pluck` | `{{ items\|pluck('key') }}` | Extract a property from each array item |
| `first_of` | `{{ items\|first_of('key') }}` | Get property from first item, fallback to `'unknown'` |
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

```yaml
# Simple flat structure
target_path: '/Products/{{ object.getItemNumber() }}/Images'

# Category hierarchy
target_path: '/Products/{{ object.getCategories()|first_of("key", "Uncategorized") }}/{{ object.getItemNumber() }}/Images'

# Locale-aware paths for localized fields
target_path: '/Products/{{ object.getItemNumber() }}/Documents{{ locale ? "/" ~ locale : "" }}'

# Date-based organization
target_path: '/Uploads/{{ date.format("Y/m") }}/{{ className }}'

# Conditional logic with Twig
target_path: '{% if has_relation(object, "categories") %}/Products/{{ object.getCategories()|first_of("key") }}{% else %}/Products/Uncategorized{% endif %}/Assets'

# Joined relation path
target_path: '/Products/{{ object.getCategories()|pluck("key")|join("/") }}/Media'

# Coalesce multiple possible identifiers
target_path: '/Products/{{ coalesce(prop(object, "getItemNumber"), prop(object, "getSku"), object.getKey()) }}/Assets'

# Fallback with slug
target_path: '/{{ className }}/{{ object.getName()|slug|fallback("unnamed") }}'
```
