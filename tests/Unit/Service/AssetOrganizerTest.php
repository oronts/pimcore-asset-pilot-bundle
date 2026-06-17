<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\BulkOrganizeEvent;
use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Strategy\StrategyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(AssetOrganizer::class)]
class AssetOrganizerTest extends TestCase
{
    private function organizer(EventDispatcher $dispatcher): AssetOrganizer
    {
        return new AssetOrganizer(
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(StrategyResolver::class),
            $this->createMock(NamingStrategyInterface::class),
            $this->createMock(AuditLogger::class),
            $dispatcher,
            $this->createMock(LoopGuard::class),
            new NullLogger(),
        );
    }

    #[Test]
    public function organizeBulkFiresBulkStartedAndCompletedEvents(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->addListener(
            AssetPilotEvents::BULK_STARTED,
            static function (BulkOrganizeEvent $e) use (&$seen): void {
                $seen[] = ['started', $e->objectIds, $e->triggerType];
            },
        );
        $dispatcher->addListener(
            AssetPilotEvents::BULK_COMPLETED,
            static function (BulkOrganizeEvent $e) use (&$seen): void {
                $seen[] = ['completed', $e->objectIds, $e->results];
            },
        );

        $this->organizer($dispatcher)->organizeBulk([], TriggerType::Api);

        self::assertSame('started', $seen[0][0]);
        self::assertSame(TriggerType::Api, $seen[0][2]);
        self::assertSame('completed', $seen[1][0]);
        self::assertSame([], $seen[1][2]);
    }
}
