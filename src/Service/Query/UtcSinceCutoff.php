<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

/**
 * The single contract for turning a user-supplied `since` value (audit CLI, replay CLI, replay REST)
 * into a stored-comparable cutoff.
 *
 * Audit timestamps are persisted in UTC, so the cutoff must be UTC too: an offset-less value is read
 * as UTC and an offset-bearing one is converted to UTC. An empty value is rejected rather than being
 * silently accepted as "now" (PHP's `\DateTimeImmutable('')` resolves to the current time).
 */
class UtcSinceCutoff
{
    /**
     * @throws \InvalidArgumentException when the value is empty
     * @throws \Exception                when the value cannot be parsed as a date/relative expression
     */
    public static function parse(string $value): string
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException('A "since" value must not be empty.');
        }

        $utc = new \DateTimeZone('UTC');

        return (new \DateTimeImmutable($value, $utc))->setTimezone($utc)->format('Y-m-d H:i:s');
    }
}
