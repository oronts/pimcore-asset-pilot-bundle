<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\EventListener;

use Oronts\AssetPilotBundle\EventListener\AssetUploadListener;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Dependency;
use Psr\Log\NullLogger;

#[CoversClass(AssetUploadListener::class)]
class AssetUploadListenerPagingTest extends TestCase
{
    /** @param list<list<array<string, mixed>>> $pages */
    private function listener(array $pages): object
    {
        return new class (
            $this->createMock(AssetOrganizer::class),
            $this->createMock(OrganizeDispatcher::class),
            $this->createMock(LoopGuard::class),
            new NullLogger(),
            true,
            true,
            $pages,
        ) extends AssetUploadListener {
            public int $fetchCount = 0;
            public int $processedCount = 0;

            /** @param list<list<array<string, mixed>>> $pages */
            public function __construct($organizer, $bus, $guard, $logger, bool $enabled, bool $async, private array $pages)
            {
                parent::__construct($organizer, $bus, $guard, $logger, $enabled, $async);
            }

            protected function fetchDependents(Dependency $dependency, int $offset, int $limit): array
            {
                $this->fetchCount++;

                return array_shift($this->pages) ?? [];
            }

            protected function processDependent(array $dep): void
            {
                $this->processedCount++;
            }

            public function run(Dependency $dependency): int
            {
                return $this->dispatchForDependents($dependency);
            }
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private static function rows(int $count): array
    {
        return array_fill(0, $count, ['type' => 'object', 'id' => 1]);
    }

    #[Test]
    public function emptyFirstPageProcessesNothingAndDoesNotLoop(): void
    {
        $listener = $this->listener([[]]);

        self::assertSame(0, $listener->run($this->createMock(Dependency::class)));
        self::assertSame(1, $listener->fetchCount);
        self::assertSame(0, $listener->processedCount);
    }

    #[Test]
    public function aShortPageStopsAfterOneFetch(): void
    {
        $listener = $this->listener([self::rows(2)]);

        self::assertSame(2, $listener->run($this->createMock(Dependency::class)));
        self::assertSame(1, $listener->fetchCount);
        self::assertSame(2, $listener->processedCount);
    }

    #[Test]
    public function aFullPageFetchesAgainUntilAShortPage(): void
    {
        // 100 == DEPENDENCY_PAGE_SIZE, so the loop must fetch a second page, then stop on the short one.
        $listener = $this->listener([self::rows(100), self::rows(3)]);

        self::assertSame(103, $listener->run($this->createMock(Dependency::class)));
        self::assertSame(2, $listener->fetchCount);
        self::assertSame(103, $listener->processedCount);
    }
}
