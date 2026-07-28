<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Command\StatusCommand;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(StatusCommand::class)]
class StatusCommandTest extends TestCase
{
    #[Test]
    public function completedTotalIncludesObserverWarnings(): void
    {
        $audit = $this->createMock(AuditQueryInterface::class);
        $audit->method('getStats')->willReturn([
            'completed' => 7,
            'completed_with_observer_error' => 2,
        ]);
        $audit->method('getRecent')->willReturn([]);
        $rules = $this->createMock(RuleEngineInterface::class);
        $rules->method('getRules')->willReturn([]);
        $tester = new CommandTester(new StatusCommand($rules, $audit, new NullLogger()));

        self::assertSame(0, $tester->execute(['--format' => 'table']));
        self::assertMatchesRegularExpression('/Completed\s+9/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Completed with observer warnings\s+2/', $tester->getDisplay());
    }

    #[Test]
    public function rejectsUnknownOutputFormatsBeforeLoadingStatus(): void
    {
        $audit = $this->createMock(AuditQueryInterface::class);
        $audit->expects(self::never())->method('getStats');
        $rules = $this->createMock(RuleEngineInterface::class);
        $rules->expects(self::never())->method('getRules');
        $tester = new CommandTester(new StatusCommand($rules, $audit, new NullLogger()));

        self::assertSame(Command::FAILURE, $tester->execute(['--format' => 'xml']));
        self::assertStringContainsString('either "table" or "json"', $tester->getDisplay());
    }
}
