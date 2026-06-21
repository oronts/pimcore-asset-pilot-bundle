<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Integrity;

use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Integrity\CompositeIntegrityChecker;
use Oronts\AssetPilotBundle\Integrity\IntegrityCheckerInterface;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;

#[CoversClass(CompositeIntegrityChecker::class)]
class CompositeIntegrityCheckerTest extends TestCase
{
    private function checker(string $name, int $priority, bool $supports): IntegrityCheckerInterface
    {
        return new class ($name, $priority, $supports) implements IntegrityCheckerInterface {
            public function __construct(private string $name, private int $priority, private bool $supports) {}

            public function priority(): int
            {
                return $this->priority;
            }

            public function supports(Asset $asset): bool
            {
                return $this->supports;
            }

            public function check(Asset $asset): IntegrityResult
            {
                return new IntegrityResult(IntegrityStatus::Renderable, $this->name);
            }

            public function checkBinary(string $binary, string $extension): IntegrityResult
            {
                return new IntegrityResult(IntegrityStatus::Renderable, $this->name);
            }
        };
    }

    #[Test]
    public function resolvesTheHighestPrioritySupportingChecker(): void
    {
        $composite = new CompositeIntegrityChecker([
            $this->checker('low', 0, true),
            $this->checker('high', 50, true),
            $this->checker('off', 99, false),
        ]);

        self::assertSame('high', $composite->check($this->createMock(Asset::class))->checker);
    }

    #[Test]
    public function unverifiableWhenNoCheckerSupportsTheAsset(): void
    {
        $composite = new CompositeIntegrityChecker([$this->checker('x', 10, false)]);

        $result = $composite->check($this->createMock(Asset::class));

        self::assertSame(IntegrityStatus::Unverifiable, $result->status);
    }
}
