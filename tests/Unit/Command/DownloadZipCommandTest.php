<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\DownloadZipCommand;
use Oronts\AssetPilotBundle\Service\AssetZipService;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DownloadZipCommand::class)]
class DownloadZipCommandTest extends TestCase
{
    #[Test]
    public function refusesToReplaceAnExistingOutputWithoutForce(): void
    {
        $output = (string) tempnam(sys_get_temp_dir(), 'apz_output_');
        $service = $this->createMock(AssetZipService::class);
        $service->expects(self::never())->method('buildFromAssetIds');
        $tester = new CommandTester(new DownloadZipCommand($service));

        try {
            $exit = $tester->execute(['--asset-ids' => '1', '--output' => $output]);

            self::assertSame(Command::FAILURE, $exit);
            self::assertStringContainsString('--force', $tester->getDisplay());
        } finally {
            @unlink($output);
        }
    }

    #[Test]
    public function writesArchiveBuiltFromObjectIdsWithRequestedOptions(): void
    {
        $archive = (string) tempnam(sys_get_temp_dir(), 'apz_archive_');
        $output = sys_get_temp_dir() . '/apz_output_' . bin2hex(random_bytes(8)) . '.zip';
        file_put_contents($archive, 'archive-content');

        $service = $this->createMock(AssetZipService::class);
        $service->expects(self::once())->method('buildFromObjects')->with(
            [7, 3],
            self::callback(static fn (ZipBuildOptions $options): bool => $options->strategy === 'folder' && $options->thumbnail === 'preview'),
        )->willReturn([
            'path' => $archive,
            'requested' => 2,
            'added' => 2,
            'skipped' => 0,
            'truncated' => false,
        ]);
        $tester = new CommandTester(new DownloadZipCommand($service));

        try {
            $exit = $tester->execute([
                '--object-ids' => '7,3',
                '--strategy' => 'folder',
                '--thumbnail' => 'preview',
                '--output' => $output,
            ]);

            self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
            self::assertSame('archive-content', file_get_contents($output));
            self::assertStringContainsString('Wrote 2 asset(s) (0 skipped)', $tester->getDisplay());
        } finally {
            @unlink($archive);
            @unlink($output);
        }
    }

}
