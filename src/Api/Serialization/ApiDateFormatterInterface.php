<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Api\Serialization;

/**
 * Serializes the bundle's stored timestamps to the RFC 3339 / ISO 8601 UTC form its OpenAPI `date-time`
 * schemas promise, so a client's `new Date(value)` resolves an unambiguous instant regardless of browser
 * timezone. Owned-table timestamps are written as UTC `Y-m-d H:i:s` (the persistence invariant); this
 * seam is the single API-boundary that turns those into `2026-07-17T20:04:05+00:00`.
 *
 * Exposed as an interface (not a static helper) so a consumer can decorate it — for invalid-value
 * telemetry, a different wire format, or a stricter policy — without touching every emission site.
 */
interface ApiDateFormatterInterface
{
    /**
     * Serialize a stored database timestamp (grammar `Y-m-d H:i:s`, interpreted as UTC) to RFC 3339.
     * A null/empty stored value returns null. A non-null value that does not match the grammar is a
     * data-integrity fault and throws, rather than silently emitting null against a non-nullable schema.
     *
     * @throws \UnexpectedValueException when a non-null value is not a valid UTC `Y-m-d H:i:s`
     */
    public function fromDatabase(?string $databaseValue): ?string;

    /** Serialize an already-typed instant (any timezone) to RFC 3339 UTC; null returns null. */
    public function fromInstant(?\DateTimeInterface $instant): ?string;
}
