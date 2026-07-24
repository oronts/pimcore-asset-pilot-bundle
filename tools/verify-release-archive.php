<?php

declare(strict_types=1);

require_once __DIR__ . '/release-manifest-assets.php';

$archive = $argv[1] ?? null;
if (!is_string($archive) || !is_file($archive)) {
    fwrite(STDERR, "Usage: php tools/verify-release-archive.php <archive.zip>\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    fwrite(STDERR, "Cannot open release archive.\n");
    exit(1);
}

try {
    $packageJson = $zip->getFromName('assets/studio/package.json');
    if ($packageJson === false) {
        throw new RuntimeException('Missing Studio package metadata.');
    }

    $package = json_decode($packageJson, true, 512, JSON_THROW_ON_ERROR);
    $version = $package['version'] ?? null;
    if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/D', $version) !== 1) {
        throw new RuntimeException('Invalid Studio package version.');
    }

    $buildId = \Oronts\AssetPilotBundle\Tools\assertActiveStudioBuildPointer($zip, $version);

    \Oronts\AssetPilotBundle\Tools\assertReleaseArchiveLayout($zip, $buildId);

    foreach ([
        'CHANGELOG.md',
        'assets/studio/package.json',
        'UPGRADING.md',
        sprintf('public/studio/build/%s/entrypoints.json', $buildId),
        sprintf('public/studio/build/%s/exposeRemote.js', $buildId),
        sprintf('public/studio/build/%s/mf-manifest.json', $buildId),
    ] as $required) {
        if ($zip->locateName($required) === false) {
            throw new RuntimeException(sprintf('Missing required archive path: %s', $required));
        }
    }

    $entrypointsPath = sprintf('public/studio/build/%s/entrypoints.json', $buildId);
    $entrypointsJson = $zip->getFromName($entrypointsPath);
    $entrypoints = json_decode((string) $entrypointsJson, true, 512, JSON_THROW_ON_ERROR);
    $remoteEntries = $entrypoints['entrypoints']['exposeRemote']['js'] ?? null;
    if (!is_array($remoteEntries) || count($remoteEntries) !== 1 || (string) $remoteEntries[0] !== sprintf('/bundles/orontsassetpilot/studio/build/%s/exposeRemote.js', $buildId)) {
        throw new RuntimeException('The archived Studio entrypoints do not expose the active remote.');
    }

    $manifestPath = sprintf('public/studio/build/%s/mf-manifest.json', $buildId);
    $manifestJson = $zip->getFromName($manifestPath);
    $manifest = json_decode((string) $manifestJson, true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['metaData']['buildInfo']['buildVersion'] ?? null) !== $version) {
        throw new RuntimeException('The archived Studio manifest does not match the package version.');
    }

    \Oronts\AssetPilotBundle\Tools\verifyReleaseManifestAssets($zip, $buildId, $entrypoints, $manifest);

    fwrite(STDOUT, sprintf("Verified release archive with Studio build %s.\n", $buildId));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    $zip->close();
}
