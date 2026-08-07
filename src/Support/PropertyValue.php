<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Support;

use Oronts\AssetPilotBundle\Enum\PropertyType;

/**
 * The single normalization contract for an asset-property mutation value, reused by the REST boundary,
 * the property service, the reviewed-plan fingerprint, and the durable rule action. A boolean property
 * accepts a native boolean or a documented textual form and rejects anything else, so a malformed value
 * can never silently overwrite metadata with false, and preview signs the exact value apply writes.
 */
final class PropertyValue
{
    /** @throws \InvalidArgumentException on an unrecognized boolean value */
    public static function normalize(PropertyType $type, string|bool|int|float $raw): string|bool
    {
        if ($type !== PropertyType::Bool) {
            return is_bool($raw) ? ($raw ? '1' : '0') : (string) $raw;
        }

        // An empty or whitespace-only string is ambiguous; reject it rather than let filter_var read it as
        // false, so a bool property is never silently unset.
        if (is_string($raw) && trim($raw) === '') {
            throw new \InvalidArgumentException('Unsupported boolean property value.');
        }

        $bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            throw new \InvalidArgumentException('Unsupported boolean property value.');
        }

        return $bool;
    }
}
