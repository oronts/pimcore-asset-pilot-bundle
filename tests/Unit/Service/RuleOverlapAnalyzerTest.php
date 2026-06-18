<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Service\RuleOverlapAnalyzer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleOverlapAnalyzer::class)]
class RuleOverlapAnalyzerTest extends TestCase
{
    /** @param Rule[] $rules */
    private function analyzer(array $rules): RuleOverlapAnalyzer
    {
        $engine = $this->createMock(RuleEngineInterface::class);
        $engine->method('getRules')->willReturn($rules);

        return new RuleOverlapAnalyzer($engine);
    }

    private function rule(string $name, string $class, array $fields, int $priority, bool $enabled = true): Rule
    {
        return new Rule($name, $class, $fields, null, '/P', \Oronts\AssetPilotBundle\Enum\MoveStrategy::Always, null, $priority, $enabled, []);
    }

    #[Test]
    public function sameClassAllFieldsOverlapWithHigherPriorityFlagged(): void
    {
        $overlaps = $this->analyzer([
            $this->rule('high', 'Product', [], 50),
            $this->rule('low', 'Product', [], 10),
        ])->analyze();

        self::assertCount(1, $overlaps);
        self::assertSame(['*'], $overlaps[0]->sharedFields);
        self::assertSame('high', $overlaps[0]->higherPriority);
        self::assertFalse($overlaps[0]->samePriority);
    }

    #[Test]
    public function disjointFieldsDoNotOverlap(): void
    {
        $overlaps = $this->analyzer([
            $this->rule('a', 'Product', ['images'], 50),
            $this->rule('b', 'Product', ['documents'], 10),
        ])->analyze();

        self::assertSame([], $overlaps);
    }

    #[Test]
    public function intersectingFieldsOverlapOnTheIntersection(): void
    {
        $overlaps = $this->analyzer([
            $this->rule('a', 'Product', ['images', 'documents'], 50),
            $this->rule('b', 'Product', ['documents', 'videos'], 10),
        ])->analyze();

        self::assertCount(1, $overlaps);
        self::assertSame(['documents'], $overlaps[0]->sharedFields);
    }

    #[Test]
    public function wildcardClassOverlapsASpecificClass(): void
    {
        $overlaps = $this->analyzer([
            $this->rule('any', '*', [], 50),
            $this->rule('product', 'Product', [], 10),
        ])->analyze();

        self::assertCount(1, $overlaps);
        self::assertSame('any', $overlaps[0]->higherPriority);
    }

    #[Test]
    public function differentClassesWithoutWildcardDoNotOverlap(): void
    {
        $overlaps = $this->analyzer([
            $this->rule('a', 'Product', [], 50),
            $this->rule('b', 'Category', [], 10),
        ])->analyze();

        self::assertSame([], $overlaps);
    }

    #[Test]
    public function disabledRulesAreExcluded(): void
    {
        $overlaps = $this->analyzer([
            $this->rule('on', 'Product', [], 50),
            $this->rule('off', 'Product', [], 10, enabled: false),
        ])->analyze();

        self::assertSame([], $overlaps);
    }

    #[Test]
    public function equalPriorityOverlapIsFlaggedAsAmbiguous(): void
    {
        $overlaps = $this->analyzer([
            $this->rule('a', 'Product', [], 10),
            $this->rule('b', 'Product', [], 10),
        ])->analyze();

        self::assertCount(1, $overlaps);
        self::assertTrue($overlaps[0]->samePriority);
        self::assertNull($overlaps[0]->higherPriority);
    }
}
