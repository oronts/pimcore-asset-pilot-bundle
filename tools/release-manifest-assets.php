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

/**
 * Deterministic SHA-256 over the Studio build inputs, byte-for-byte compatible with `source-hash.mjs` (paths
 * sorted, content LF-normalized, each `path\0content\0`). Must stay in sync with the JS or the verifier rejects
 * a valid release.
 *
 * @param array<string, string> $sourceFiles studio-relative path => raw content
 */
function computeStudioSourceHash(array $sourceFiles): string
{
    $paths = array_keys($sourceFiles);
    sort($paths, SORT_STRING);

    $hash = hash_init('sha256');
    foreach ($paths as $relative) {
        hash_update($hash, $relative);
        hash_update($hash, "\0");
        hash_update($hash, str_replace("\r\n", "\n", $sourceFiles[$relative]));
        hash_update($hash, "\0");
    }

    return hash_final($hash);
}

/**
 * The Studio build-input files present in the release archive (`js/src/**`, `rsbuild.config.ts`,
 * `tsconfig.json`, `package.json`, `package-lock.json`, `scripts/manifest-assets.mjs`, `scripts/publish-build.mjs` under `assets/studio/`), keyed by
 * studio-relative path. Must stay in sync with source-hash.mjs SOURCE_ROOTS. Mirrors the JS collector's
 * exclusions (`node_modules` and dotfiles), so the recomputed hash matches the one the build recorded.
 *
 * @return array<string, string>
 */
function studioSourceFilesFromArchive(ZipArchive $zip): array
{
    $files = [];
    for ($index = 0; $index < $zip->numFiles; ++$index) {
        $name = $zip->getNameIndex($index);
        if (!is_string($name) || str_ends_with($name, '/') || !str_starts_with($name, 'assets/studio/')) {
            continue;
        }
        $relative = substr($name, strlen('assets/studio/'));
        if (!str_starts_with($relative, 'js/src/')
            && $relative !== 'rsbuild.config.ts'
            && $relative !== 'tsconfig.json'
            && $relative !== 'package.json'
            && $relative !== 'package-lock.json'
            && $relative !== 'scripts/manifest-assets.mjs'
            && $relative !== 'scripts/publish-build.mjs'
        ) {
            continue;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === 'node_modules' || str_starts_with($segment, '.')) {
                continue 2;
            }
        }
        $content = $zip->getFromIndex($index);
        if ($content === false) {
            throw new RuntimeException('Cannot read archived Studio source: ' . $relative);
        }
        $files[$relative] = $content;
    }

    return $files;
}

/**
 * Validates the archived active Studio build pointer and returns its build id. Requires a valid
 * `sourceHash` so a release archive whose shipped build was not proven fresh against its Studio source
 * (the check `npm run verify-build` performs) is rejected here too, not just by the JS verifier.
 */
function assertActiveStudioBuildPointer(ZipArchive $zip, string $version): string
{
    $pointer = $zip->getFromName('public/studio/build/active.json');
    if ($pointer === false) {
        throw new RuntimeException('Missing active Studio build pointer.');
    }

    $active = json_decode($pointer, true, 512, JSON_THROW_ON_ERROR);
    $buildId = $active['buildId'] ?? null;
    if (!is_string($buildId) || !isValidReleaseBuildId($buildId)) {
        throw new RuntimeException('Invalid active Studio build pointer.');
    }
    if (!str_starts_with($buildId, $version . '-')) {
        throw new RuntimeException('The active Studio build does not match the package version.');
    }

    $sourceHash = $active['sourceHash'] ?? null;
    if (!is_string($sourceHash) || preg_match('/^[0-9a-f]{64}$/', $sourceHash) !== 1) {
        throw new RuntimeException('The active Studio build pointer is missing a valid sourceHash; the shipped build cannot be proven fresh against its Studio source (run `npm run verify-build`).');
    }

    // Recompute the source hash from the ARCHIVED Studio source and compare, so a stale build in the archive
    // (source changed without a rebuild) is caught here, not only by the JS verifier at build time.
    $sourceFiles = studioSourceFilesFromArchive($zip);
    if ($sourceFiles === []) {
        throw new RuntimeException('The release archive contains no Studio source (assets/studio/js/src, rsbuild.config.ts, tsconfig.json, package.json, package-lock.json, scripts/manifest-assets.mjs, scripts/publish-build.mjs) to prove the build fresh.');
    }
    if (!hash_equals($sourceHash, computeStudioSourceHash($sourceFiles))) {
        throw new RuntimeException('The active Studio build pointer sourceHash does not match the archived Studio source; the shipped build is stale (rebuild: npm run build + prepare-release-build).');
    }

    return $buildId;
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
