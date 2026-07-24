<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Service\RuleExecutionFingerprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleExecutionFingerprint::class)]
final class RuleExecutionFingerprintTest extends TestCase
{
    #[Test]
    public function itIsDeterministicForTheSameRule(): void
    {
        $fp = new RuleExecutionFingerprint();
        $rule = $this->rule(['actions' => ['a' => ['type' => 'set_property'], 'b' => ['type' => 'watermark']], 'options' => ['quality' => 0.85]]);

        self::assertSame($fp->forRule($rule), $fp->forRule($this->rule(['actions' => ['a' => ['type' => 'set_property'], 'b' => ['type' => 'watermark']], 'options' => ['quality' => 0.85]])));
    }

    #[Test]
    public function itBindsActionExecutionOrderEvenForAnAssociativelyKeyedActionMap(): void
    {
        // DurableRuleActionObserver runs array_values($actions), i.e. insertion order, keys ignored. A
        // map-shaped actions array whose insertion order differs is a different execution and must hash
        // differently, even though ksort would collapse both to the same key order.
        $fp = new RuleExecutionFingerprint();
        $resizeThenStamp = $this->rule(['actions' => ['resize' => ['type' => 'resize'], 'stamp' => ['type' => 'watermark']]]);
        $stampThenResize = $this->rule(['actions' => ['stamp' => ['type' => 'watermark'], 'resize' => ['type' => 'resize']]]);

        self::assertNotSame(
            $fp->forRule($resizeThenStamp),
            $fp->forRule($stampThenResize),
            'reordering the action map changes execution order, so the fingerprint must change',
        );
    }

    #[Test]
    public function itIsInsensitiveToOptionKeyOrder(): void
    {
        $fp = new RuleExecutionFingerprint();

        self::assertSame(
            $fp->forRule($this->rule(['options' => ['quality' => 90, 'sharpen' => true]])),
            $fp->forRule($this->rule(['options' => ['sharpen' => true, 'quality' => 90]])),
            'options are an unordered map, so their key order must not change the fingerprint',
        );
    }

    #[Test]
    public function itBindsEveryBehaviourAffectingRuleValue(): void
    {
        $fp = new RuleExecutionFingerprint();
        $base = $this->rule([]);
        $baseHash = $fp->forRule($base);

        self::assertNotSame($baseHash, $fp->forRule($this->rule(['strategy' => 'first_assignment'])), 'strategy');
        self::assertNotSame($baseHash, $fp->forRule($this->rule(['callback' => 'app.custom'])), 'callback');
        self::assertNotSame($baseHash, $fp->forRule($this->rule(['options' => ['quality' => 0.5]])), 'options');
        self::assertNotSame($baseHash, $fp->forRule($this->rule(['actions' => [['type' => 'set_property']]])), 'actions');
        self::assertNotSame($baseHash, $fp->forRule($this->rule(['target_path' => '/other'])), 'target_path');
    }

    /** @param array<string, mixed> $overrides */
    private function rule(array $overrides): Rule
    {
        return Rule::fromConfig('r', array_merge(['class' => 'Product', 'target_path' => '/t'], $overrides));
    }
}
