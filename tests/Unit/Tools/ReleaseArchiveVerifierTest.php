<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Tools;

use function Oronts\AssetPilotBundle\Tools\assertActiveStudioBuildPointer;
use function Oronts\AssetPilotBundle\Tools\assertReleaseArchiveLayout;
use function Oronts\AssetPilotBundle\Tools\computeStudioSourceHash;
use function Oronts\AssetPilotBundle\Tools\isValidReleaseBuildId;
use function Oronts\AssetPilotBundle\Tools\verifyReleaseManifestAssets;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

require_once dirname(__DIR__, 3) . '/tools/release-manifest-assets.php';

#[CoversNothing]
final class ReleaseArchiveVerifierTest extends TestCase
{
    private const string BUILD_ID = '2.0.0-test';

    /** @return array<string, string> studio-relative path => content */
    private static function studioSource(): array
    {
        return [
            'js/src/main.ts' => "export const boot = (): string => 'ok'\n",
            'js/src/app/index.ts' => "export const app = 1\n",
            'rsbuild.config.ts' => "export default {}\n",
            'tsconfig.json' => "{\"compilerOptions\":{\"baseUrl\":\".\"}}\n",
            'package.json' => json_encode(['name' => 'studio', 'version' => '2.0.0'], JSON_THROW_ON_ERROR),
            'package-lock.json' => json_encode(['name' => 'studio', 'lockfileVersion' => 3], JSON_THROW_ON_ERROR),
            'scripts/manifest-assets.mjs' => "export const isValidBuildId = (id) => Boolean(id)\n",
            'scripts/publish-build.mjs' => "export const publish = () => 0\n",
        ];
    }

    /** @var list<string> */
    private array $archives = [];

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            @unlink($archive);
        }
        $this->archives = [];
    }

    #[Test]
    public function rejectsBuildIdentifiersThatCanEscapeTheBuildRoot(): void
    {
        self::assertFalse(isValidReleaseBuildId('.'));
        self::assertFalse(isValidReleaseBuildId('..'));
    }

    #[Test]
    public function acceptsOneCompleteActiveBuildGeneration(): void
    {
        $this->verifyArchive($this->archive());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsAnActivePointerWithoutAValidSourceHash(): void
    {
        $archive = $this->archive(static function (array &$files): void {
            $files['public/studio/build/active.json'] = json_encode(['buildId' => self::BUILD_ID], JSON_THROW_ON_ERROR);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing a valid sourceHash');

        $this->verifyArchive($archive);
    }

    #[Test]
    public function rejectsAStaleBuildWhoseArchivedSourceNoLongerMatchesItsSourceHash(): void
    {
        $archive = $this->archive(static function (array &$files): void {
            $files['assets/studio/js/src/main.ts'] = "export const boot = (): string => 'TAMPERED'\n";
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match the archived Studio source');

        $this->verifyArchive($archive);
    }

    #[Test]
    public function rejectsAStaleBuildWhoseArchivedLockfileNoLongerMatchesItsSourceHash(): void
    {
        // A dependency bump changes package-lock.json but not js/src; without it in the hash a stale remote
        // would pass, so the recompute must reject it.
        $archive = $this->archive(static function (array &$files): void {
            $files['assets/studio/package-lock.json'] = json_encode(['name' => 'studio', 'lockfileVersion' => 3, 'bumped' => true], JSON_THROW_ON_ERROR);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match the archived Studio source');

        $this->verifyArchive($archive);
    }

    #[Test]
    public function rejectsAStaleBuildWhoseArchivedTsconfigNoLongerMatchesItsSourceHash(): void
    {
        // tsconfig feeds rsbuild path/baseUrl resolution, so a changed tsconfig without a rebuild must fail.
        $archive = $this->archive(static function (array &$files): void {
            $files['assets/studio/tsconfig.json'] = "{\"compilerOptions\":{\"baseUrl\":\"./src\"}}\n";
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match the archived Studio source');

        $this->verifyArchive($archive);
    }

    #[Test]
    public function rejectsAStaleBuildWhoseArchivedPublisherNoLongerMatchesItsSourceHash(): void
    {
        // publish-build.mjs chooses the build command, environment, id, output paths, and recorded hash;
        // a change to it without a rebuild can alter the published artifact, so it must fail the recompute.
        $archive = $this->archive(static function (array &$files): void {
            $files['assets/studio/scripts/publish-build.mjs'] = "export const publish = () => 1\n";
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match the archived Studio source');

        $this->verifyArchive($archive);
    }

    #[Test]
    public function rejectsExternalEntrypointAssetEvenWhenItEndsWithTheExpectedRemote(): void
    {
        $archive = $this->archive(static function (array &$files): void {
            $entrypoints = json_decode($files[self::buildPath('entrypoints.json')], true, 512, JSON_THROW_ON_ERROR);
            $entrypoints['entrypoints']['main']['js'] = ['https://example.test/' . self::BUILD_ID . '/main.js'];
            $files[self::buildPath('entrypoints.json')] = json_encode($entrypoints, JSON_THROW_ON_ERROR);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('External manifest asset is forbidden');

        $this->verifyArchive($archive);
    }

    #[Test]
    public function rejectsTraversalInTheFederationManifest(): void
    {
        $archive = $this->archive(static function (array &$files): void {
            $manifest = json_decode($files[self::buildPath('mf-manifest.json')], true, 512, JSON_THROW_ON_ERROR);
            $manifest['exposes'][0]['assets']['js']['sync'] = ['static/js/%2e%2e/escape.js'];
            $files[self::buildPath('mf-manifest.json')] = json_encode($manifest, JSON_THROW_ON_ERROR);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Manifest asset traversal is forbidden');

        $this->verifyArchive($archive);
    }

    #[Test]
    public function rejectsMissingFederationAsset(): void
    {
        $archive = $this->archive(static function (array &$files): void {
            unset($files[self::buildPath('static/js/remoteEntry.js')]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing or empty manifest asset: static/js/remoteEntry.js');

        $this->verifyArchive($archive);
    }

    #[Test]
    public function rejectsBrowserE2eDevelopmentSources(): void
    {
        $archive = $this->archive(static function (array &$files): void {
            $files['assets/studio/e2e/playwright.config.ts'] = 'export default {};';
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forbidden archive path: assets/studio/e2e/playwright.config.ts');

        $this->verifyArchive($archive);
    }

    #[Test]
    public function rejectsASecondGenerationWithTheSamePackageVersion(): void
    {
        $archive = $this->archive(static function (array &$files): void {
            $files['public/studio/build/2.0.0-other/static/js/stale.js'] = 'stale';
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Archive must contain only the active Studio build generation');

        $this->verifyArchive($archive);
    }

    private function verifyArchive(string $archive): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive));
        try {
            assertActiveStudioBuildPointer($zip, '2.0.0');
            assertReleaseArchiveLayout($zip, self::BUILD_ID);
            $entrypoints = json_decode((string) $zip->getFromName(self::buildPath('entrypoints.json')), true, 512, JSON_THROW_ON_ERROR);
            $manifest = json_decode((string) $zip->getFromName(self::buildPath('mf-manifest.json')), true, 512, JSON_THROW_ON_ERROR);
            verifyReleaseManifestAssets($zip, self::BUILD_ID, $entrypoints, $manifest);
        } finally {
            $zip->close();
        }
    }

    private function archive(?\Closure $mutate = null): string
    {
        $files = $this->files();
        if ($mutate !== null) {
            $mutate($files);
        }

        $archive = tempnam(sys_get_temp_dir(), 'asset-pilot-release-');
        if ($archive === false) {
            self::fail('Could not create a temporary archive path.');
        }
        $this->archives[] = $archive;
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($files as $path => $contents) {
            self::assertTrue($zip->addFromString($path, $contents));
        }
        self::assertTrue($zip->close());

        return $archive;
    }

    /** @return array<string, string> */
    private function files(): array
    {
        $publicPrefix = '/bundles/orontsassetpilot/studio/build/' . self::BUILD_ID . '/';
        $entrypoints = [
            'entrypoints' => [
                'exposeRemote' => ['js' => [$publicPrefix . 'exposeRemote.js'], 'css' => []],
                'main' => ['js' => [$publicPrefix . 'static/js/main.js'], 'css' => [$publicPrefix . 'static/css/main.css']],
            ],
        ];
        $manifest = [
            'metaData' => [
                'buildInfo' => ['buildVersion' => '2.0.0'],
                'remoteEntry' => ['name' => 'static/js/remoteEntry.js'],
            ],
            'exposes' => [[
                'assets' => [
                    'js' => ['sync' => ['static/js/main.js'], 'async' => []],
                    'css' => ['sync' => ['static/css/main.css'], 'async' => []],
                ],
            ]],
        ];

        $sourceHash = computeStudioSourceHash(self::studioSource());
        $files = [
            'public/studio/build/active.json' => json_encode(['buildId' => self::BUILD_ID, 'sourceHash' => $sourceHash], JSON_THROW_ON_ERROR),
            self::buildPath('entrypoints.json') => json_encode($entrypoints, JSON_THROW_ON_ERROR),
            self::buildPath('mf-manifest.json') => json_encode($manifest, JSON_THROW_ON_ERROR),
            self::buildPath('exposeRemote.js') => 'remote',
            self::buildPath('static/js/main.js') => 'main',
            self::buildPath('static/js/remoteEntry.js') => 'federation',
            self::buildPath('static/css/main.css') => 'style',
        ];
        foreach (self::studioSource() as $relative => $content) {
            $files['assets/studio/' . $relative] = $content;
        }

        return $files;
    }

    private static function buildPath(string $relativePath): string
    {
        return sprintf('public/studio/build/%s/%s', self::BUILD_ID, $relativePath);
    }
}
