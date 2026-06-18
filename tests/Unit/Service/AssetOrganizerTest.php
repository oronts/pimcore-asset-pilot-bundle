<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Event\BulkOrganizeEvent;
use Oronts\AssetPilotBundle\Model\OperationResult;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Strategy\StrategyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
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

    #[Test]
    public function skipsWhenTheLockIsUnavailableAndReleasesNothing(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireAsset')->willReturn(false);
        $loopGuard->expects(self::never())->method('releaseAsset');

        $result = $this->moveAsset($loopGuard, $this->asset('/source/f.jpg', locked: false));

        self::assertSame(OperationStatus::Skipped, $result->status);
    }

    #[Test]
    public function skipsLockedAssetsBeforeAcquiringTheLock(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::never())->method('acquireAsset');

        $result = $this->moveAsset($loopGuard, $this->asset('/source/f.jpg', locked: true));

        self::assertSame(OperationStatus::Skipped, $result->status);
    }

    #[Test]
    public function skipsWhenTheAssetIsAlreadyAtTheTarget(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::never())->method('acquireAsset');

        $result = $this->moveAsset($loopGuard, $this->asset('/target/f.jpg', locked: false));

        self::assertSame(OperationStatus::Skipped, $result->status);
    }

    private function moveAsset(LoopGuard $loopGuard, Asset $asset): OperationResult
    {
        $organizer = new class (
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(StrategyResolver::class),
            $this->createMock(NamingStrategyInterface::class),
            $this->createMock(AuditLogger::class),
            new EventDispatcher(),
            $loopGuard,
            new NullLogger(),
        ) extends AssetOrganizer {
            public function moveOne(Asset $a, AbstractObject $o, Rule $r): OperationResult
            {
                return $this->moveAsset($a, '/target', 'f.jpg', $o, $r, TriggerType::Manual);
            }
        };

        return $organizer->moveOne(
            $asset,
            $this->createMock(AbstractObject::class),
            Rule::fromConfig('r', ['class' => 'Product', 'target_path' => '/target']),
        );
    }

    private function asset(string $path, bool $locked): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);
        $asset->method('getRealFullPath')->willReturn($path);
        $asset->method('hasProperty')->willReturn($locked);
        $asset->method('getProperty')->willReturn($locked ? '1' : null);

        return $asset;
    }
}
