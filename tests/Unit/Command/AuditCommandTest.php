<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Audit\AuditRetentionInterface;
use Oronts\AssetPilotBundle\Command\AuditCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AuditCommand::class)]
final class AuditCommandTest extends TestCase
{
    #[Test]
    public function invalidSinceReturnsInvalidWithoutQueryingTheAuditLog(): void
    {
        $query = $this->createMock(AuditQueryInterface::class);
        $query->expects(self::never())->method('getRecent');
        $tester = new CommandTester(new AuditCommand($query, $this->createMock(AuditRetentionInterface::class)));

        $exitCode = $tester->execute(['--since' => '%%% definitely not a date %%%']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--since must be a valid date', $tester->getDisplay());
    }

    #[Test]
    public function cleanupUsesConfiguredRetentionAndSkipsTheListingQuery(): void
    {
        $query = $this->createMock(AuditQueryInterface::class);
        $query->expects(self::never())->method('getRecent');
        $retention = $this->createMock(AuditRetentionInterface::class);
        $retention->expects(self::once())->method('getRetentionDays')->willReturn(30);
        $retention->expects(self::once())->method('cleanup')->with(30)->willReturn(7);
        $tester = new CommandTester(new AuditCommand($query, $retention));

        $exitCode = $tester->execute(['--cleanup' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Cleaned up 7 audit entries older than 30 days.', $tester->getDisplay());
    }

    #[Test]
    public function filteredEntriesAreQueriedAndRendered(): void
    {
        $filters = [
            'object_class' => 'Product',
            'status' => 'completed',
            'rule_name' => 'images',
            'asset_id' => 17,
            'object_id' => 23,
        ];
        $query = $this->createMock(AuditQueryInterface::class);
        $query->expects(self::once())->method('getRecent')->with(5, $filters)->willReturn([[
            'id' => 1,
            'asset_id' => 17,
            'asset_path_from' => '/source/image.jpg',
            'asset_path_to' => '/target/image.jpg',
            'object_class' => 'Product',
            'rule_name' => 'images',
            'status' => 'completed',
            'duration_ms' => 12,
            'created_at' => '2026-07-15 10:00:00',
        ]]);
        $tester = new CommandTester(new AuditCommand($query, $this->createMock(AuditRetentionInterface::class)));

        $exitCode = $tester->execute([
            '--class' => 'Product',
            '--status' => 'completed',
            '--rule' => 'images',
            '--asset-id' => '17',
            '--object-id' => '23',
            '--limit' => '5',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('/target/image.jpg', $tester->getDisplay());
        self::assertStringContainsString('12ms', $tester->getDisplay());
        self::assertStringContainsString('1 entries shown.', $tester->getDisplay());
    }

}
