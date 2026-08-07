<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Oronts\AssetPilotBundle\Model\DependencyExtraction;
use Oronts\AssetPilotBundle\Service\AssetDependencyTargetExtractor;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Oronts\AssetPilotBundle\Service\LiveDependencyUsageScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
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

    #[Test]
    public function failsClosedWhenAClassificationStoreSourceIsIncompletelyResolved(): void
    {
        $source = $this->createMock(AbstractObject::class);
        $source->method('resolveDependencies')->willReturn([]);
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(7);

        // The object's classification-store refs cannot be fully resolved, so the whole live scan must fail
        // closed to Unknown rather than certifying any asset Safe from a partial index.
        $scanner = $this->scanner([$source], 10, new DependencyExtraction([], false));
        self::assertSame(DependencyUsageVerdict::Unknown, $scanner->verdict($asset));
    }

    /** @param list<AbstractElement> $sources */
    private function scanner(array $sources, int $maxSources = 10, ?DependencyExtraction $classification = null): LiveDependencyUsageScanner
    {
        $fieldExtractor = $this->createStub(AssetFieldExtractorInterface::class);
        $fieldExtractor->method('classificationStoreAssetIds')->willReturn($classification ?? new DependencyExtraction([], true));
        $targetExtractor = new AssetDependencyTargetExtractor($fieldExtractor);

        return new class ((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(), $targetExtractor, new NullLogger(), $maxSources, $sources) extends LiveDependencyUsageScanner {
            /** @param list<AbstractElement> $sources */
            public function __construct(Connection $connection, AssetDependencyTargetExtractor $targetExtractor, NullLogger $logger, int $maxSources, private readonly array $sources)
            {
                parent::__construct($connection, $targetExtractor, $logger, $maxSources);
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
