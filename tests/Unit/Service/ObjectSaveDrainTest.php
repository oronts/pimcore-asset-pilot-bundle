<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Service\AutomaticOrganizeIntentStoreInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrain;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(ObjectSaveDrain::class)]
final class ObjectSaveDrainTest extends TestCase
{
    #[Test]
    public function durablyReOrganizesAndClearsTheBulkSyncDirtyFlagWhenDirty(): void
    {
        $actor = ActorContext::user(7);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('isObjectDirty')->with(42)->willReturn(true);
        $loopGuard->expects(self::once())->method('clearObjectDirty')->with(42);
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('deferObject')->with(42, TriggerType::ObjectSave, $actor);
        $dispatcher->expects(self::never())->method('dispatchObject');

        $this->drain($loopGuard, $dispatcher)->drain(42, TriggerType::ObjectSave, $actor);
    }

    #[Test]
    public function doesNotReDispatchWhenNotDirty(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('isObjectDirty')->with(42)->willReturn(false);
        $loopGuard->expects(self::never())->method('clearObjectDirty');
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatchObject');

        $this->drain($loopGuard, $dispatcher)->drain(42, TriggerType::ObjectSave, ActorContext::system());
    }

    #[Test]
    public function subsumesTheCacheDirtyFlagUnderADurableRotation(): void
    {
        $actor = ActorContext::user(7);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('isObjectDirty')->willReturn(true);
        $loopGuard->expects(self::once())->method('clearObjectDirty')->with(42);
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('deferObject')->with(42, TriggerType::ObjectSave, $actor);
        $dispatcher->expects(self::never())->method('dispatchObject');
        $intents = $this->createMock(AutomaticOrganizeIntentStoreInterface::class);
        $intents->method('releaseIfOwnedBy')->with(42, 'run-1')->willReturn(true);

        $this->drain($loopGuard, $dispatcher, $intents)->drain(42, TriggerType::ObjectSave, $actor, 'run-1');
    }

    #[Test]
    public function releasesTheOwnRunIntentAndDurablyReOrganizesWhenItCoalescedASave(): void
    {
        $actor = ActorContext::user(7);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('isObjectDirty')->willReturn(false);
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('deferObject')->with(42, TriggerType::ObjectSave, $actor);
        $dispatcher->expects(self::never())->method('dispatchObject');
        $intents = $this->createMock(AutomaticOrganizeIntentStoreInterface::class);
        $intents->expects(self::once())->method('releaseIfOwnedBy')->with(42, 'run-1')->willReturn(true);

        $this->drain($loopGuard, $dispatcher, $intents)->drain(42, TriggerType::ObjectSave, $actor, 'run-1');
    }

    #[Test]
    public function releasesACleanOwnRunIntentWithoutReOrganizing(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('isObjectDirty')->willReturn(false);
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('deferObject');
        $dispatcher->expects(self::never())->method('dispatchObject');
        $intents = $this->createMock(AutomaticOrganizeIntentStoreInterface::class);
        $intents->expects(self::once())->method('releaseIfOwnedBy')->with(42, 'run-1')->willReturn(false);

        $this->drain($loopGuard, $dispatcher, $intents)->drain(42, TriggerType::ObjectSave, ActorContext::system(), 'run-1');
    }

    #[Test]
    public function doesNotTouchAnyIntentWhenNoRunIsGiven(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('isObjectDirty')->willReturn(false);
        $intents = $this->createMock(AutomaticOrganizeIntentStoreInterface::class);
        $intents->expects(self::never())->method('releaseIfOwnedBy');

        $this->drain($loopGuard, null, $intents)->drain(42, TriggerType::ObjectSave, ActorContext::system());
    }

    #[Test]
    public function neverThrowsWhenTheDirtyReadFails(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('isObjectDirty')->willThrowException(new \RuntimeException('cache unavailable'));

        $this->drain($loopGuard)->drain(42, TriggerType::ObjectSave, ActorContext::system());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function neverThrowsWhenTheReDispatchFails(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('isObjectDirty')->willReturn(true);
        $loopGuard->expects(self::never())->method('clearObjectDirty');
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->method('deferObject')->willThrowException(new \RuntimeException('broker down'));

        $this->drain($loopGuard, $dispatcher)->drain(42, TriggerType::ObjectSave, ActorContext::system());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function neverThrowsEvenWhenTheLoggerThrowsInsideTheCatch(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('isObjectDirty')->willReturn(true);
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->method('deferObject')->willThrowException(new \RuntimeException('broker down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willThrowException(new \RuntimeException('logger down'));

        (new ObjectSaveDrain($loopGuard, $dispatcher, $this->createMock(AutomaticOrganizeIntentStoreInterface::class), $this->passthroughConnection(), $logger))
            ->drain(42, TriggerType::ObjectSave, ActorContext::system());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rotateStaleReplacementReleasesTheRunIntentAndGuaranteesExactlyOneReplacement(): void
    {
        $actor = ActorContext::user(7);
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('deferObject')->with(42, TriggerType::ObjectSave, $actor);
        $dispatcher->expects(self::never())->method('dispatchObject');
        $intents = $this->createMock(AutomaticOrganizeIntentStoreInterface::class);
        $intents->expects(self::once())->method('releaseIfOwnedBy')->with(42, 'run-1')->willReturn(true);

        $this->drain($this->createMock(LoopGuard::class), $dispatcher, $intents)->rotateStaleReplacement(42, TriggerType::ObjectSave, $actor, 'run-1');
    }

    #[Test]
    public function rotateStaleReplacementForAnUntrackedMessageDefersWithoutTouchingAnyIntent(): void
    {
        $actor = ActorContext::system();
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('deferObject')->with(42, TriggerType::ObjectSave, $actor);
        $intents = $this->createMock(AutomaticOrganizeIntentStoreInterface::class);
        $intents->expects(self::never())->method('releaseIfOwnedBy');

        $this->drain($this->createMock(LoopGuard::class), $dispatcher, $intents)->rotateStaleReplacement(42, TriggerType::ObjectSave, $actor, null);
    }

    #[Test]
    public function rotateStaleReplacementNeverThrowsWhenTheDeferFails(): void
    {
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->method('deferObject')->willThrowException(new \RuntimeException('broker down'));

        $this->drain($this->createMock(LoopGuard::class), $dispatcher)->rotateStaleReplacement(42, TriggerType::ObjectSave, ActorContext::system(), 'run-1');

        $this->addToAssertionCount(1);
    }

    private function drain(LoopGuard $loopGuard, ?OrganizeDispatcherInterface $dispatcher = null, ?AutomaticOrganizeIntentStoreInterface $intents = null): ObjectSaveDrain
    {
        return new ObjectSaveDrain(
            $loopGuard,
            $dispatcher ?? $this->createMock(OrganizeDispatcherInterface::class),
            $intents ?? $this->createMock(AutomaticOrganizeIntentStoreInterface::class),
            $this->passthroughConnection(),
            new NullLogger(),
        );
    }

    private function passthroughConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $work): mixed => $work($connection));

        return $connection;
    }
}
