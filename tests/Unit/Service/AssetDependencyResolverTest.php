<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\AssetDependencyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AssetDependencyResolver::class)]
class AssetDependencyResolverTest extends TestCase
{
    /** @param array<int, list<array{id: int, type: string}>> $pagesByAsset offset-keyed pages */
    private function resolver(array $pages): AssetDependencyResolver
    {
        return new class ($pages) extends AssetDependencyResolver {
            /** @param list<array{id: int, type: string}> $pages */
            public function __construct(private array $pages)
            {
                parent::__construct(new NullLogger());
            }

            protected function loadRequiredBy(int $assetId, int $offset, int $limit): array
            {
                return array_slice($this->pages, $offset, $limit);
            }
        };
    }

    #[Test]
    public function returnsOnlyObjectDependenciesDeduplicated(): void
    {
        $ids = $this->resolver([
            ['id' => 10, 'type' => 'object'],
            ['id' => 20, 'type' => 'document'],
            ['id' => 10, 'type' => 'object'],
            ['id' => 30, 'type' => 'object'],
        ])->dependentObjectIds(1);

        self::assertSame([10, 30], $ids);
    }

    #[Test]
    public function isBoundedByTheLimit(): void
    {
        $pages = [];
        for ($i = 1; $i <= 250; $i++) {
            $pages[] = ['id' => $i, 'type' => 'object'];
        }

        $ids = $this->resolver($pages)->dependentObjectIds(1, 5);

        self::assertCount(5, $ids);
        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    #[Test]
    public function emptyWhenNothingDependsOnTheAsset(): void
    {
        self::assertSame([], $this->resolver([])->dependentObjectIds(1));
    }
}
