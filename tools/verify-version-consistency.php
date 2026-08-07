<?php

declare(strict_types=1);

/**
 * Fails when the version-bearing sources in the repository disagree. Run without arguments (CI) to
 * assert the working tree is internally consistent, or with a release tag (the release workflow) to
 * additionally bind the tag to those sources.
 *
 * Canonical source: composer.json .version. Every other source must equal it. Set ASSET_PILOT_ROOT to
 * point the check at a fixture tree in tests.
 *
 * Sources checked:
 *   - composer.json .version                                   (canonical)
 *   - assets/studio/package.json .version
 *   - public/studio/build/active.json .buildId                 (must be "<version>-<uuid>")
 *   - CHANGELOG.md first released "## [x.y.z]" heading
 *   - UPGRADING.md first "## Upgrade to x.y.z" heading
 *   - the release tag argument, when given                     ("vx.y.z")
 */

$root = getenv('ASSET_PILOT_ROOT');
if (!is_string($root) || $root === '') {
    $root = dirname(__DIR__);
}
$root = rtrim($root, '/');

$tag = $argv[1] ?? null;

$errors = [];

/** @return array{version: ?string, error: ?string} */
$jsonVersion = static function (string $file): array {
    if (!is_file($file)) {
        return ['version' => null, 'error' => 'missing file'];
    }
    try {
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return ['version' => null, 'error' => 'invalid JSON: ' . $e->getMessage()];
    }
    $version = is_array($data) ? ($data['version'] ?? null) : null;

    return is_string($version)
        ? ['version' => $version, 'error' => null]
        : ['version' => null, 'error' => 'no string .version'];
};

$composer = $jsonVersion("$root/composer.json");
$canonical = $composer['version'];
if ($canonical === null) {
    fwrite(STDERR, 'FATAL: cannot read composer.json .version (' . ($composer['error'] ?? 'unknown') . ").\n");
    exit(1);
}

$expect = static function (string $label, ?string $version, ?string $error) use ($canonical, &$errors): void {
    if ($error !== null) {
        $errors[] = sprintf('%s: %s', $label, $error);

        return;
    }
    if ($version !== $canonical) {
        $errors[] = sprintf('%s is %s, expected %s', $label, var_export($version, true), $canonical);
    }
};

$studio = $jsonVersion("$root/assets/studio/package.json");
$expect('assets/studio/package.json', $studio['version'], $studio['error']);

// Active Studio build: buildId is "<version>-<uuid>", so it must start with "<canonical>-".
$activeFile = "$root/public/studio/build/active.json";
if (!is_file($activeFile)) {
    $errors[] = 'public/studio/build/active.json: missing file';
} else {
    try {
        $active = json_decode((string) file_get_contents($activeFile), true, 512, JSON_THROW_ON_ERROR);
        $buildId = is_array($active) ? ($active['buildId'] ?? null) : null;
        if (!is_string($buildId)) {
            $errors[] = 'public/studio/build/active.json: no string .buildId';
        } elseif (!str_starts_with($buildId, $canonical . '-')) {
            $errors[] = sprintf('active Studio build %s does not match version %s', var_export($buildId, true), $canonical);
        }
    } catch (JsonException $e) {
        $errors[] = 'public/studio/build/active.json: invalid JSON: ' . $e->getMessage();
    }
}

$headingVersion = static function (string $file, string $pattern): array {
    if (!is_file($file)) {
        return ['version' => null, 'error' => 'missing file'];
    }
    if (preg_match($pattern, (string) file_get_contents($file), $m) !== 1) {
        return ['version' => null, 'error' => 'no version heading found'];
    }

    return ['version' => $m[1], 'error' => null];
};

$changelog = $headingVersion("$root/CHANGELOG.md", '/^## \[(\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.]+)?)\]/m');
$expect('CHANGELOG.md latest release heading', $changelog['version'], $changelog['error']);

$upgrading = $headingVersion("$root/UPGRADING.md", '/^## Upgrade to (\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.]+)?)/m');
$expect('UPGRADING.md latest upgrade heading', $upgrading['version'], $upgrading['error']);

if (is_string($tag) && $tag !== '') {
    if ($tag !== 'v' . $canonical) {
        $errors[] = sprintf('release tag %s does not match v%s', var_export($tag, true), $canonical);
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Version consistency check FAILED (canonical composer.json version = $canonical):\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - $error\n");
    }
    exit(1);
}

fwrite(STDOUT, sprintf("Version consistency OK: all sources agree on %s%s.\n", $canonical, is_string($tag) && $tag !== '' ? " (tag $tag)" : ''));
exit(0);
