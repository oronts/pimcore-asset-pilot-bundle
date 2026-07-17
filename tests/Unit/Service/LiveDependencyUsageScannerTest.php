<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Oronts\AssetPilotBundle\Service\LiveDependencyUsageScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\AbstractElement;
use Psr\Log\NullLogger;

#[CoversClass(LiveDependencyUsageScanner::class)]
class LiveDependencyUsageScannerTest extends TestCase
{
    #[Test]
    public function resolvesCurrentPimcoreDependenciesInsteadOfTrustingTheIndex(): void
    {
        $source = $this->createMock(AbstractElement::class);
        $source->method('resolveDependencies')->willReturn([
            ['id' => 7, 'type' => 'asset'],
            ['id' => 7, 'type' => 'object'],
        ]);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(7);

        self::assertSame(DependencyUsageVerdict::Referenced, $this->scanner([$source])->verdict($asset));
    }

    #[Test]
    public function failsClosedWhenTheSourceBudgetIsExceeded(): void
    {
        $source = $this->createMock(AbstractElement::class);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(7);

        self::assertSame(DependencyUsageVerdict::Unknown, $this->scanner([$source, $source], 1)->verdict($asset));
    }

    /** @param list<AbstractElement> $sources */
    private function scanner(array $sources, int $maxSources = 10): LiveDependencyUsageScanner
    {
        return new class ((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(), new NullLogger(), $maxSources, $sources) extends LiveDependencyUsageScanner {
            /** @param list<AbstractElement> $sources */
            public function __construct(Connection $connection, NullLogger $logger, int $maxSources, private readonly array $sources)
            {
                parent::__construct($connection, $logger, $maxSources);
            }

            protected function sourceElements(): \Generator
            {
                foreach ($this->sources as $source) {
                    yield $source;
                }
            }
        };
    }
}
