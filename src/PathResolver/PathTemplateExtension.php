<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\PathResolver;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The built-in filters and functions available in rule `target_path` templates. Registered on the
 * resolver's Twig environment; consumer-tagged `oronts_asset_pilot.twig_extension` services are layered
 * on top. See docs/path-templates.md.
 */
class PathTemplateExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            // |safe_key — sanitize a value for use as a path segment
            new TwigFilter('safe_key', static function (mixed $value): string {
                if ($value === null || $value === '' || $value === false) {
                    return 'unknown';
                }
                $str = (string) $value;
                return preg_replace('/[^a-zA-Z0-9_\-.]/', '-', $str) ?: 'unknown';
            }),

            // |pluck('property') — extract a property/method from each item in an array
            // Usage: {{ categories|pluck('code') }} → ['A-100', 'A-200']
            // Usage: {{ categories|pluck('getCode') }} → same, calls method
            new TwigFilter('pluck', static function (mixed $items, string $property): array {
                if (!is_iterable($items)) {
                    return [];
                }

                $values = [];
                foreach ($items as $item) {
                    if (is_object($item)) {
                        // Try getter first
                        $getter = 'get' . ucfirst($property);
                        if (method_exists($item, $getter)) {
                            $val = $item->$getter();
                        } elseif (method_exists($item, $property)) {
                            $val = $item->$property();
                        } elseif (property_exists($item, $property)) {
                            $val = $item->$property;
                        } else {
                            $val = null;
                        }

                        if ($val !== null) {
                            $values[] = (string) $val;
                        }
                    } elseif (is_array($item) && isset($item[$property])) {
                        $values[] = (string) $item[$property];
                    }
                }

                return $values;
            }),

            // |first_of('property') — get property from first item, or 'unknown'
            // Usage: {{ categories|first_of('code') }} → 'A-100'
            new TwigFilter('first_of', static function (mixed $items, string $property, string $fallback = 'unknown'): string {
                if (!is_iterable($items)) {
                    return $fallback;
                }

                foreach ($items as $item) {
                    if (is_object($item)) {
                        $getter = 'get' . ucfirst($property);
                        if (method_exists($item, $getter)) {
                            $val = $item->$getter();
                            if ($val !== null && $val !== '') {
                                return (string) $val;
                            }
                        }
                        if (method_exists($item, $property)) {
                            $val = $item->$property();
                            if ($val !== null && $val !== '') {
                                return (string) $val;
                            }
                        }
                    }
                    break; // only check first
                }

                return $fallback;
            }),

            // |slug — URL-safe slug
            new TwigFilter('slug', static function (mixed $value): string {
                if ($value === null || $value === '') {
                    return 'unknown';
                }
                $str = (string) $value;
                $str = mb_strtolower($str);
                $str = preg_replace('/[^a-z0-9]+/', '-', $str) ?? $str;
                return trim($str, '-') ?: 'unknown';
            }),

            // |fallback('default') — like |default but also catches empty strings
            new TwigFilter('fallback', static function (mixed $value, string $default = 'unknown'): string {
                if ($value === null || $value === '' || $value === false) {
                    return $default;
                }
                return (string) $value;
            }),

            // |trim_path — remove leading/trailing slashes
            new TwigFilter('trim_path', static function (mixed $value): string {
                return trim((string) ($value ?? ''), '/');
            }),
        ];
    }

    public function getFunctions(): array
    {
        return [
            // coalesce(val1, val2, ...) — first non-null, non-empty value
            new TwigFunction('coalesce', static function (mixed ...$values): string {
                foreach ($values as $value) {
                    if ($value !== null && $value !== '' && $value !== false) {
                        return (string) $value;
                    }
                }
                return 'unknown';
            }, ['is_variadic' => true]),

            // prop(obj, 'method', ...args) — call a read accessor on an object. Restricted to get/is/has
            // accessors so a path template (admin-authored, but still) cannot invoke a mutating method.
            new TwigFunction('prop', static function (mixed $obj, string $method, mixed ...$args): mixed {
                if ($obj === null || !is_object($obj) || !method_exists($obj, $method)) {
                    return null;
                }
                if (!preg_match('/^(get|is|has)[A-Z0-9]/', $method)) {
                    return null;
                }
                return $obj->$method(...$args);
            }, ['is_variadic' => true]),

            // rel(object, 'relation', index) — safely access a relation item
            new TwigFunction('rel', static function (mixed $object, string $relation, int $index = 0): mixed {
                if ($object === null || !is_object($object)) {
                    return null;
                }

                $getter = 'get' . ucfirst($relation);
                if (!method_exists($object, $getter)) {
                    return null;
                }

                $items = $object->$getter();
                if (!is_array($items)) {
                    return is_object($items) ? $items : null;
                }

                return $items[$index] ?? null;
            }),

            // has_relation(object, 'relation') — check if relation has items
            new TwigFunction('has_relation', static function (mixed $object, string $relation): bool {
                if ($object === null || !is_object($object)) {
                    return false;
                }

                $getter = 'get' . ucfirst($relation);
                if (!method_exists($object, $getter)) {
                    return false;
                }

                $items = $object->$getter();
                if (is_array($items)) {
                    return !empty($items);
                }

                return $items !== null;
            }),
        ];
    }
}
