<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Strategy;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Strategy\ConflictStrategyInterface;
use Oronts\AssetPilotBundle\Strategy\StrategyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(StrategyResolver::class)]
class StrategyResolverTest extends TestCase
{
    private function createRule(MoveStrategy $strategy): Rule
    {
        return new Rule(
            name: 'test', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: $strategy, callback: null,
            priority: 10, enabled: true, filters: [],
        );
    }

    #[Test]
    public function resolvesMatchingStrategy(): void
    {
        $strategy1 = $this->createMock(ConflictStrategyInterface::class);
        $strategy1->method('supports')->willReturnCallback(
            fn (MoveStrategy $s) => $s === MoveStrategy::Always,
        );

        $strategy2 = $this->createMock(ConflictStrategyInterface::class);
        $strategy2->method('supports')->willReturnCallback(
            fn (MoveStrategy $s) => $s === MoveStrategy::FirstAssignment,
        );

        $resolver = new StrategyResolver([$strategy1, $strategy2], new NullLogger());

        self::assertSame($strategy1, $resolver->resolve($this->createRule(MoveStrategy::Always)));
        self::assertSame($strategy2, $resolver->resolve($this->createRule(MoveStrategy::FirstAssignment)));
    }

    #[Test]
    public function throwsWhenNoStrategyMatches(): void
    {
        $strategy = $this->createMock(ConflictStrategyInterface::class);
        $strategy->method('supports')->willReturn(false);

        $resolver = new StrategyResolver([$strategy], new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No conflict strategy found');

        $resolver->resolve($this->createRule(MoveStrategy::Callback));
    }

    #[Test]
    public function returnsFirstMatchingStrategy(): void
    {
        $strategy1 = $this->createMock(ConflictStrategyInterface::class);
        $strategy1->method('supports')->willReturn(true);

        $strategy2 = $this->createMock(ConflictStrategyInterface::class);
        $strategy2->method('supports')->willReturn(true);

        $resolver = new StrategyResolver([$strategy1, $strategy2], new NullLogger());

        self::assertSame($strategy1, $resolver->resolve($this->createRule(MoveStrategy::Always)));
    }

    #[Test]
    public function acceptsTraversable(): void
    {
        $strategy = $this->createMock(ConflictStrategyInterface::class);
        $strategy->method('supports')->willReturn(true);

        $generator = (function () use ($strategy) {
            yield $strategy;
        })();

        $resolver = new StrategyResolver($generator, new NullLogger());

        self::assertSame($strategy, $resolver->resolve($this->createRule(MoveStrategy::Always)));
    }
}
