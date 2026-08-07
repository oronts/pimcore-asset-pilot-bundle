<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Webpack;

use Oronts\AssetPilotBundle\Webpack\WebpackEntryPointProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebpackEntryPointProvider::class)]
class WebpackEntryPointProviderTest extends TestCase
{
    private string $buildRoot;

    protected function setUp(): void
    {
        $this->buildRoot = sys_get_temp_dir() . '/asset-pilot-webpack-' . bin2hex(random_bytes(8));
        mkdir($this->buildRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->buildRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->buildRoot);
    }

    #[Test]
    public function exposesOnlyTheActiveBuild(): void
    {
        mkdir($this->buildRoot . '/current');
        mkdir($this->buildRoot . '/previous');
        touch($this->buildRoot . '/current/entrypoints.json');
        touch($this->buildRoot . '/previous/entrypoints.json');
        file_put_contents($this->buildRoot . '/active.json', '{"buildId":"current"}');

        $provider = new WebpackEntryPointProvider($this->buildRoot);

        self::assertSame([$this->buildRoot . '/current/entrypoints.json'], $provider->getEntryPointsJsonLocations());
    }

    #[Test]
    public function returnsNoEntryPointWithoutAnActiveBuild(): void
    {
        self::assertSame([], (new WebpackEntryPointProvider($this->buildRoot))->getEntryPointsJsonLocations());
    }

    #[Test]
    public function rejectsAnUnsafeBuildIdentifier(): void
    {
        file_put_contents($this->buildRoot . '/active.json', '{"buildId":"../outside"}');

        $this->expectException(\UnexpectedValueException::class);
        (new WebpackEntryPointProvider($this->buildRoot))->getEntryPointsJsonLocations();
    }
}
