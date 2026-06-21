<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Service\RulePortability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RulePortability::class)]
class RulePortabilityTest extends TestCase
{
    /** @param Rule[] $rules */
    private function portability(array $rules): RulePortability
    {
        $engine = $this->createMock(RuleEngineInterface::class);
        $engine->method('getRules')->willReturn($rules);

        return new RulePortability($engine);
    }

    #[Test]
    public function exportIsFormatVersionedAndKeyedByRuleName(): void
    {
        $a = Rule::fromConfig('a', ['class' => 'Product', 'target_path' => '/A']);
        $b = Rule::fromConfig('b', ['class' => 'Category', 'target_path' => '/B', 'priority' => 5]);

        $artifact = $this->portability([$a, $b])->export();

        self::assertSame(RulePortability::FORMAT_VERSION, $artifact['format_version']);
        self::assertSame(['a', 'b'], array_keys($artifact['rules']));
        self::assertSame($a->toConfigArray(), $artifact['rules']['a']);
        self::assertArrayNotHasKey('name', $artifact['rules']['a']);
    }

    #[Test]
    public function diffClassifiesAddedRemovedChangedAndUnchanged(): void
    {
        $current = [
            Rule::fromConfig('keep', ['class' => 'Product', 'target_path' => '/Keep']),
            Rule::fromConfig('drop', ['class' => 'Product', 'target_path' => '/Drop']),
            Rule::fromConfig('edit', ['class' => 'Product', 'target_path' => '/Edit', 'priority' => 10]),
        ];

        $diff = $this->portability($current)->diff(['rules' => [
            'keep' => ['class' => 'Product', 'target_path' => '/Keep'],
            'edit' => ['class' => 'Product', 'target_path' => '/Edit', 'priority' => 99],
            'add' => ['class' => 'Category', 'target_path' => '/Add'],
        ]]);

        self::assertSame(['add'], array_keys($diff->added));
        self::assertSame(['drop'], array_keys($diff->removed));
        self::assertSame(['edit'], array_keys($diff->changed));
        self::assertSame(['keep'], $diff->unchanged);
        self::assertTrue($diff->hasChanges());
        self::assertSame(10, $diff->changed['edit']['current']['priority']);
        self::assertSame(99, $diff->changed['edit']['imported']['priority']);
    }

    #[Test]
    public function diffCanonicalizesSoDefaultOmittedFieldsAreNotFalseChanges(): void
    {
        // Current rule carries all defaults; the imported config omits them but is semantically equal.
        $current = [Rule::fromConfig('r', ['class' => 'Product', 'target_path' => '/P'])];

        $diff = $this->portability($current)->diff(['rules' => [
            'r' => ['class' => 'Product', 'target_path' => '/P'],
        ]]);

        self::assertSame(['r'], $diff->unchanged);
        self::assertFalse($diff->hasChanges());
    }

    #[Test]
    public function roundTripExportThenDiffReportsNoChanges(): void
    {
        $current = [
            Rule::fromConfig('a', ['class' => 'Product', 'target_path' => '/A', 'fields' => ['img']]),
            Rule::fromConfig('b', ['class' => 'Category', 'target_path' => '/B', 'enabled' => false]),
        ];
        $portability = $this->portability($current);

        $diff = $portability->diff($portability->export());

        self::assertFalse($diff->hasChanges());
        self::assertSame(['a', 'b'], $diff->unchanged);
    }

    #[Test]
    public function diffKeepsAnUncanonicalizableImportedRuleVisibleAsAChange(): void
    {
        // A malformed imported rule (no target_path) must still surface in the diff, not blow it up.
        $current = [Rule::fromConfig('r', ['class' => 'Product', 'target_path' => '/P'])];

        $diff = $this->portability($current)->diff(['rules' => [
            'r' => ['class' => 'Product'],
        ]]);

        self::assertArrayHasKey('r', $diff->changed);
        self::assertTrue($diff->hasChanges());
    }
}
