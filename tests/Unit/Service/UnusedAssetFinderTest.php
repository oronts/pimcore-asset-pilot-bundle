<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Service\ConfidenceScorer;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(UnusedAssetFinder::class)]
class UnusedAssetFinderTest extends TestCase
{
    private function finder(): UnusedAssetFinder
    {
        return new UnusedAssetFinder(
            $this->createMock(Connection::class),
            new NullLogger(),
            $this->createMock(ConfidenceScorer::class),
            new EventDispatcher(),
        );
    }

    #[Test]
    public function findUnusedRejectsMinSizeFilterInsteadOfSilentlyIgnoringIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->finder()->findUnused(['minSize' => 1024]);
    }

    #[Test]
    public function findUnusedRejectsMaxSizeFilter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->finder()->findUnused(['maxSize' => 1024]);
    }

    #[Test]
    public function countUnusedRejectsSizeFilters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->finder()->countUnused(['minSize' => 1, 'maxSize' => 2]);
    }
}
