[← Documentation index](index.md) · [Project README](../README.md)

# Expression Language

Rule conditions use Symfony ExpressionLanguage. Four variables are available: `object`, `asset`, `rule`, and `locale` (the field locale for a localized asset field, e.g. `en`/`de`, or `null` for a non-localized field). For example, `locale == "de"` matches only the German variant of a localized field. To restrict a whole rule to specific locales without a condition, use the rule's `locales` key (see [Configuration](configuration.md)).

Conditions are trusted deployment configuration, not end-user input. They receive live Pimcore
models and may invoke public model methods, including during preview, drift, explain, and dry-run
requests. Restrict configuration write access, review expressions as executable application logic,
and do not accept condition strings from untrusted users.

Drift evaluation still evaluates rule conditions, but it does not dispatch pre-move events or call
callback strategies. Those mutation-adjacent hooks run only for an explicit preview or move request.

### Built-in Functions

| Function | Returns | Example |
|----------|---------|---------|
| `asset_type(asset)` | `string` | `asset_type(asset) == "image"` |
| `asset_size(asset)` | `int` | `asset_size(asset) > 1048576` |
| `asset_extension(asset)` | `string` | `asset_extension(asset) == "pdf"` |
| `object_class(object)` | `string` | `object_class(object) == "Product"` |
| `is_image(asset)` | `bool` | `is_image(asset)` |
| `is_video(asset)` | `bool` | `is_video(asset)` |
| `is_document(asset)` | `bool` | `is_document(asset)` |
| `has_property(element, name)` | `bool` | `has_property(asset, "source")` |
| `path_matches(asset, pattern)` | `bool` | `path_matches(asset, "#/temp/#")` |

Consumers can add custom condition functions by tagging an `ExpressionFunctionProviderInterface` service (`oronts_asset_pilot.expression_function_provider`). See [Extending](extending.md).

### Condition Examples

Each example is a standalone `condition` for one rule. They are shown in separate blocks because a
single YAML document cannot repeat the `condition` key.

```yaml
# Only objects that have an item number set
condition: 'object.getItemNumber() != null'
```

```yaml
# Only images under 10 MB
condition: 'is_image(asset) and asset_size(asset) < 10485760'
```

```yaml
# Only PDF files
condition: 'asset_extension(asset) == "pdf"'
```

```yaml
# Compound: images over 1 MB from Product class
condition: 'is_image(asset) and asset_size(asset) > 1048576 and object_class(object) == "Product"'
```

```yaml
# Skip assets already in the target structure
condition: 'not path_matches(asset, "#^/Products/#")'
```

```yaml
# Only process assets with a specific property
condition: 'has_property(asset, "approved") and has_property(asset, "reviewed")'
```

```yaml
# Match only videos or documents (no images)
condition: 'is_video(asset) or is_document(asset)'
```

Literal `path_matches()` patterns are checked by `asset-pilot:validate-config`. A malformed pattern
fails closed at runtime and is logged instead of emitting a PHP warning.
