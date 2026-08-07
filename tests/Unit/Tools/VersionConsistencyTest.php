<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class VersionConsistencyTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/ap-vc-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/assets/studio', 0o777, true);
        mkdir($this->fixture . '/public/studio/build', 0o777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->fixture)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fixture, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->fixture);
    }

    #[Test]
    public function acceptsAConsistentTree(): void
    {
        $this->seed('2.0.0', '2.0.0', '2.0.0-uuid', '2.0.0', '2.0.0');
        [$code] = $this->runScript();
        self::assertSame(0, $code);
    }

    #[Test]
    public function acceptsAConsistentTreeWithAMatchingTag(): void
    {
        $this->seed('2.0.0', '2.0.0', '2.0.0-uuid', '2.0.0', '2.0.0');
        [$code] = $this->runScript('v2.0.0');
        self::assertSame(0, $code);
    }

    #[Test]
    public function rejectsAMismatchedStudioVersion(): void
    {
        $this->seed('2.0.0', '1.9.9', '2.0.0-uuid', '2.0.0', '2.0.0');
        [$code, $err] = $this->runScript();
        self::assertSame(1, $code);
        self::assertStringContainsString('assets/studio/package.json', $err);
    }

    #[Test]
    public function rejectsAMismatchedActiveBuild(): void
    {
        $this->seed('2.0.0', '2.0.0', '1.9.9-uuid', '2.0.0', '2.0.0');
        [$code, $err] = $this->runScript();
        self::assertSame(1, $code);
        self::assertStringContainsString('active Studio build', $err);
    }

    #[Test]
    public function rejectsAMismatchedChangelogHeading(): void
    {
        $this->seed('2.0.0', '2.0.0', '2.0.0-uuid', '1.9.9', '2.0.0');
        [$code, $err] = $this->runScript();
        self::assertSame(1, $code);
        self::assertStringContainsString('CHANGELOG', $err);
    }

    #[Test]
    public function rejectsAMismatchedUpgradingHeading(): void
    {
        $this->seed('2.0.0', '2.0.0', '2.0.0-uuid', '2.0.0', '1.9.9');
        [$code, $err] = $this->runScript();
        self::assertSame(1, $code);
        self::assertStringContainsString('UPGRADING', $err);
    }

    #[Test]
    public function rejectsAMismatchedTag(): void
    {
        $this->seed('2.0.0', '2.0.0', '2.0.0-uuid', '2.0.0', '2.0.0');
        [$code, $err] = $this->runScript('v9.9.9');
        self::assertSame(1, $code);
        self::assertStringContainsString('release tag', $err);
    }

    private function seed(string $composer, string $studio, string $buildId, string $changelog, string $upgrading): void
    {
        file_put_contents($this->fixture . '/composer.json', (string) json_encode(['version' => $composer]));
        file_put_contents($this->fixture . '/assets/studio/package.json', (string) json_encode(['version' => $studio]));
        file_put_contents($this->fixture . '/public/studio/build/active.json', (string) json_encode(['buildId' => $buildId]));
        file_put_contents($this->fixture . '/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n## [$changelog] - 2026-01-01\n");
        file_put_contents($this->fixture . '/UPGRADING.md', "# Upgrading\n\n## Upgrade to $upgrading\n");
    }

    /** @return array{0: int, 1: string} */
    private function runScript(?string $tag = null): array
    {
        $script = dirname(__DIR__, 3) . '/tools/verify-version-consistency.php';
        $cmd = 'ASSET_PILOT_ROOT=' . escapeshellarg($this->fixture) . ' php ' . escapeshellarg($script);
        if ($tag !== null) {
            $cmd .= ' ' . escapeshellarg($tag);
        }
        $cmd .= ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);

        return [$code, implode("\n", $out)];
    }
}
