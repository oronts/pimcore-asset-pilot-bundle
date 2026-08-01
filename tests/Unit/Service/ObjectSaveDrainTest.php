<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrain;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ObjectSaveDrain::class)]
final class ObjectSaveDrainTest extends TestCase
{
    #[Test]
    public function clearsTheDispatchMarkerThenReDispatchesAndClearsDirtyWhenDirty(): void
    {
        $actor = ActorContext::user(7);
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::once())->method('clearObjectDispatched')->with(42);
        $loopGuard->method('isObjectDirty')->with(42)->willReturn(true);
        $loopGuard->expects(self::once())->method('clearObjectDirty')->with(42);
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatchObject')->with(42, TriggerType::ObjectSave, $actor);

        $this->drain($loopGuard, $dispatcher)->drain(42, TriggerType::ObjectSave, $actor);
    }

    #[Test]
    public function clearsTheDispatchMarkerButDoesNotReDispatchWhenNotDirty(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->expects(self::once())->method('clearObjectDispatched')->with(42);
        $loopGuard->method('isObjectDirty')->with(42)->willReturn(false);
        $loopGuard->expects(self::never())->method('clearObjectDirty');
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatchObject');

        $this->drain($loopGuard, $dispatcher)->drain(42, TriggerType::ObjectSave, ActorContext::system());
    }

    #[Test]
    public function neverThrowsWhenTheMarkerReadFails(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('clearObjectDispatched')->willThrowException(new \RuntimeException('cache unavailable'));

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
        $dispatcher->method('dispatchObject')->willThrowException(new \RuntimeException('broker down'));

        $this->drain($loopGuard, $dispatcher)->drain(42, TriggerType::ObjectSave, ActorContext::system());

        $this->addToAssertionCount(1);
    }

    private function drain(LoopGuard $loopGuard, ?OrganizeDispatcherInterface $dispatcher = null): ObjectSaveDrain
    {
        return new ObjectSaveDrain($loopGuard, $dispatcher ?? $this->createMock(OrganizeDispatcherInterface::class), new NullLogger());
    }
}
