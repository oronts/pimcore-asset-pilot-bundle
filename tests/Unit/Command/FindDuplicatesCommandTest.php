<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\FindDuplicatesCommand;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(FindDuplicatesCommand::class)]
final class FindDuplicatesCommandTest extends TestCase
{
    #[Test]
    public function scanFiltersAlsoScopeTheReportAndCount(): void
    {
        $filters = ['folder' => '/wanted', 'type' => 'image', 'extension' => 'png'];
        $service = $this->createMock(DuplicateDetectionService::class);
        $service->expects(self::once())
            ->method('index')
            ->with($filters, 25)
            ->willReturn(['scanned' => 2, 'indexed' => 2, 'skipped' => 0]);
        $service->expects(self::once())
            ->method('findDuplicates')
            ->with(1, 10, 2, null, $filters)
            ->willReturn([new DuplicateGroup('checksum', 100, 2, [1, 2])]);
        $service->expects(self::once())
            ->method('countDuplicateGroups')
            ->with(2, null, $filters)
            ->willReturn(1);
        $tester = new CommandTester(new FindDuplicatesCommand($service));

        $status = $tester->execute([
            '--scan' => true,
            '--folder' => '/wanted',
            '--type' => 'image',
            '--extension' => 'png',
            '--limit' => '25',
            '--report-limit' => '10',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Indexed 2 asset(s)', $tester->getDisplay());
    }
}
