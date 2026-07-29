<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api\Support;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strict readers for JSON-body mutation scalars, so a POST endpoint rejects a malformed value with a
 * consistent 400 instead of coercing it (e.g. "async":"false" silently becoming true). Each returns a
 * value or a ready-to-send JsonResponse; callers guard with `instanceof JsonResponse`, mirroring
 * HandlesBulkIds. Absent (or explicit null) yields the supplied default.
 */
trait ReadsRequestScalars
{
    /**
     * @param array<string, mixed> $data
     */
    protected function requestString(array $data, string $key, ?string $default): string|JsonResponse
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return $default ?? new JsonResponse(['error' => sprintf('%s is required.', $key)], Response::HTTP_BAD_REQUEST);
        }

        if (!is_string($data[$key])) {
            return new JsonResponse(['error' => sprintf('%s must be a string.', $key)], Response::HTTP_BAD_REQUEST);
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function requestOptionalString(array $data, string $key): string|JsonResponse|null
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (!is_string($data[$key])) {
            return new JsonResponse(['error' => sprintf('%s must be a string.', $key)], Response::HTTP_BAD_REQUEST);
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function requestBool(array $data, string $key, bool $default): bool|JsonResponse
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return $default;
        }

        if (!is_bool($data[$key])) {
            return new JsonResponse(['error' => sprintf('%s must be a boolean.', $key)], Response::HTTP_BAD_REQUEST);
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return ($default is null ? int|JsonResponse|null : int|JsonResponse)
     */
    protected function requestOptionalPositiveInt(array $data, string $key, ?int $max, ?int $default): int|JsonResponse|null
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return $default;
        }

        return $this->parsePositiveInt($data[$key], $key, $max);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function requestPositiveInt(array $data, string $key, ?int $max): int|JsonResponse
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return new JsonResponse(['error' => sprintf('%s is required.', $key)], Response::HTTP_BAD_REQUEST);
        }

        return $this->parsePositiveInt($data[$key], $key, $max);
    }

    private function parsePositiveInt(mixed $value, string $key, ?int $max): int|JsonResponse
    {
        // The round-trip rejects a leading-zero or an over-PHP_INT_MAX digit string that (int) would
        // otherwise saturate to a bogus value.
        $id = is_int($value)
            ? $value
            : (is_string($value) && ctype_digit($value) && $value === (string) (int) $value ? (int) $value : null);

        if ($id === null || $id < 1 || ($max !== null && $id > $max)) {
            $bound = $max !== null ? sprintf(' and at most %d', $max) : '';

            return new JsonResponse(['error' => sprintf('%s must be a positive integer%s.', $key, $bound)], Response::HTTP_BAD_REQUEST);
        }

        return $id;
    }
}
