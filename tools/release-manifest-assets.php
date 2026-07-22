<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tools;

use RuntimeException;
use ZipArchive;

function isValidReleaseBuildId(string $buildId): bool
{
    return preg_match('/^[A-Za-z0-9._-]+$/D', $buildId) === 1
        && $buildId !== '.'
        && $buildId !== '..';
}

function verifyReleaseManifestAssets(ZipArchive $zip, string $buildId, array $entrypoints, array $manifest): void
{
    if (!isValidReleaseBuildId($buildId)) {
        throw new RuntimeException('The Studio build identifier is invalid.');
    }
    $references = [...entrypointAssetReferences($entrypoints), ...federationManifestAssetReferences($manifest)];
    if ($references === []) {
        throw new RuntimeException('The archived Studio manifests do not reference any JavaScript or CSS assets.');
    }

    $verified = [];
    foreach ($references as $reference) {
        $relativePath = normalizeReleaseAssetReference($reference['value'], $buildId, $reference['type']);
        if (isset($verified[$relativePath])) {
            continue;
        }

        $archivePath = sprintf('public/studio/build/%s/%s', $buildId, $relativePath);
        $index = $zip->locateName($archivePath);
        $stats = $index === false ? false : $zip->statIndex($index);
        if ($stats === false || ($stats['size'] ?? 0) < 1) {
            throw new RuntimeException(sprintf('Missing or empty manifest asset: %s', $relativePath));
        }
        $verified[$relativePath] = true;
    }
}

function assertReleaseArchiveLayout(ZipArchive $zip, string $buildId): void
{
    if (!isValidReleaseBuildId($buildId)) {
        throw new RuntimeException('The Studio build identifier is invalid.');
    }
    $seenPaths = [];
    $generations = [];
    for ($index = 0; $index < $zip->numFiles; ++$index) {
        $archivePath = $zip->getNameIndex($index);
        if (!is_string($archivePath) || $archivePath === '') {
            throw new RuntimeException('Invalid empty archive path.');
        }
        if (isset($seenPaths[$archivePath])) {
            throw new RuntimeException(sprintf('Duplicate archive path: %s', $archivePath));
        }
        $seenPaths[$archivePath] = true;

        if (unsafeReleaseArchivePath($archivePath)) {
            throw new RuntimeException(sprintf('Forbidden archive path: %s', $archivePath));
        }
        if (preg_match('~^public/studio/build/([^/]+)/~', $archivePath, $matches) === 1) {
            $generations[$matches[1]] = true;
        }
    }

    $found = array_keys($generations);
    if ($found !== [$buildId]) {
        throw new RuntimeException(sprintf(
            'Archive must contain only the active Studio build generation %s; found %s.',
            $buildId,
            implode(', ', $found),
        ));
    }
}

function normalizeReleaseAssetReference(string $reference, string $buildId, string $expectedType): string
{
    if (!isValidReleaseBuildId($buildId)) {
        throw new RuntimeException('The Studio build identifier is invalid.');
    }
    if ($reference === '' || trim($reference) !== $reference) {
        throw new RuntimeException('Manifest assets must be non-empty strings without surrounding whitespace.');
    }
    if (str_contains($reference, '\\') || str_contains($reference, '?') || str_contains($reference, '#')) {
        throw new RuntimeException(sprintf('Manifest asset is not a normalized local path: %s', $reference));
    }
    if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $reference) === 1 || str_starts_with($reference, '//')) {
        throw new RuntimeException(sprintf('External manifest asset is forbidden: %s', $reference));
    }

    $publicPrefix = sprintf('/bundles/orontsassetpilot/studio/build/%s/', $buildId);
    if (str_starts_with($reference, $publicPrefix)) {
        $relativePath = substr($reference, strlen($publicPrefix));
    } elseif (str_starts_with($reference, '/')) {
        throw new RuntimeException(sprintf('Absolute manifest asset is forbidden: %s', $reference));
    } else {
        $relativePath = $reference;
    }

    foreach (explode('/', $relativePath) as $segment) {
        $decoded = rawurldecode($segment);
        if ($segment === '' || $segment === '.' || $segment === '..'
            || $decoded === '.' || $decoded === '..'
            || str_contains($decoded, '/') || str_contains($decoded, '\\')
        ) {
            throw new RuntimeException(sprintf('Manifest asset traversal is forbidden: %s', $reference));
        }
    }
    if (preg_match(sprintf('/\.%s$/Di', preg_quote($expectedType, '/')), $relativePath) !== 1) {
        throw new RuntimeException(sprintf('Manifest asset has an invalid type: %s', $reference));
    }

    return $relativePath;
}

/** @return list<array{value: string, type: string}> */
function entrypointAssetReferences(array $document): array
{
    $entrypoints = $document['entrypoints'] ?? null;
    if (!is_array($entrypoints)) {
        throw new RuntimeException('The archived Studio entrypoints document is invalid.');
    }

    $references = [];
    foreach ($entrypoints as $name => $entrypoint) {
        if (!is_array($entrypoint)) {
            throw new RuntimeException(sprintf('Studio entrypoint %s is invalid.', (string) $name));
        }
        foreach (['js', 'css'] as $type) {
            if (!array_key_exists($type, $entrypoint)) {
                continue;
            }
            if (!is_array($entrypoint[$type])) {
                throw new RuntimeException(sprintf('Studio entrypoint %s.%s must be an array.', (string) $name, $type));
            }
            foreach ($entrypoint[$type] as $value) {
                if (!is_string($value)) {
                    throw new RuntimeException(sprintf('Studio entrypoint %s.%s contains a non-string asset.', (string) $name, $type));
                }
                $references[] = ['value' => $value, 'type' => $type];
            }
        }
    }

    return $references;
}

/** @return list<array{value: string, type: string}> */
function federationManifestAssetReferences(array $manifest): array
{
    $remoteEntry = $manifest['metaData']['remoteEntry']['name'] ?? null;
    if (!is_string($remoteEntry)) {
        throw new RuntimeException('The federation manifest remote entry is invalid.');
    }

    $references = [['value' => $remoteEntry, 'type' => 'js']];
    collectTypedReleaseAssetGroups($manifest, $references);

    return $references;
}

/** @param list<array{value: string, type: string}> $references */
function collectTypedReleaseAssetGroups(mixed $value, array &$references): void
{
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $key => $child) {
        if ($key === 'js' || $key === 'css') {
            collectReleaseAssetLeaves($child, $key, $references);
        } else {
            collectTypedReleaseAssetGroups($child, $references);
        }
    }
}

/** @param list<array{value: string, type: string}> $references */
function collectReleaseAssetLeaves(mixed $value, string $type, array &$references): void
{
    if (is_string($value)) {
        $references[] = ['value' => $value, 'type' => $type];

        return;
    }
    if (!is_array($value)) {
        throw new RuntimeException(sprintf('Federation manifest %s assets contain an invalid value.', $type));
    }
    foreach ($value as $child) {
        collectReleaseAssetLeaves($child, $type, $references);
    }
}

function unsafeReleaseArchivePath(string $path): bool
{
    if (str_starts_with($path, '/') || str_contains($path, '\\')
        || preg_match('~(^|/)(vendor|tests|e2e|node_modules|development|\.[^/]+)/~', $path) === 1
        || preg_match('~(^|/)\.active-.*\.json$~', $path) === 1
    ) {
        return true;
    }
    foreach (explode('/', rtrim($path, '/')) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return true;
        }
    }

    return false;
}
