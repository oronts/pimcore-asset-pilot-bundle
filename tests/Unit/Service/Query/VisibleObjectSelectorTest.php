<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service\Query;

use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\Query\VisibleObjectSelector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;

#[CoversClass(VisibleObjectSelector::class)]
final class VisibleObjectSelectorTest extends TestCase
{
    #[Test]
    public function pageReturnsOnlyVisibleObjectsWithoutAnExactTotal(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (AbstractObject $object): bool => $object->getId() !== 2,
        );

        $page = $this->selector($authorization, $this->objectMocks([1, 2, 3]), [1, 2, 3])->page('Product', 0, 1);

        self::assertSame([1], array_column($page['objects'], 'id'));
        self::assertTrue($page['hasMore']);
        self::assertFalse($page['truncated']);
    }

    #[Test]
    public function pageStopsAtTheCandidateBudgetAndReportsTruncated(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(false);
        $ids = range(1, 100);

        $page = $this->selector($authorization, $this->objectMocks($ids), $ids, 10)->page('Product', 0, 5);

        self::assertSame([], $page['objects']);
        self::assertFalse($page['hasMore']);
        self::assertTrue($page['truncated']);
    }

    #[Test]
    public function pageHasMoreReflectsAnAuthorizedSurplusPastThePage(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);

        $exact = $this->selector($authorization, $this->objectMocks([1, 2]), [1, 2])->page('Product', 0, 2);
        self::assertSame([1, 2], array_column($exact['objects'], 'id'));
        self::assertFalse($exact['hasMore']);

        $surplus = $this->selector($authorization, $this->objectMocks([1, 2, 3]), [1, 2, 3])->page('Product', 0, 2);
        self::assertSame([1, 2], array_column($surplus['objects'], 'id'));
        self::assertTrue($surplus['hasMore']);
    }

    #[Test]
    public function pageAppliesTheVisibleOffsetAcrossPages(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(true);

        $page = $this->selector($authorization, $this->objectMocks([1, 2, 3, 4]), [1, 2, 3, 4])->page('Product', 2, 2);

        self::assertSame([3, 4], array_column($page['objects'], 'id'));
        self::assertFalse($page['hasMore']);
    }

    #[Test]
    public function resolveIdsCollectsEveryViewVisibleId(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (AbstractObject $object): bool => $object->getId() !== 2,
        );

        $resolved = $this->selector($authorization, $this->objectMocks([1, 2, 3]), [1, 2, 3])->resolveIds('Product');

        self::assertSame([1, 3], $resolved['ids']);
        self::assertFalse($resolved['truncated']);
    }

    #[Test]
    public function resolveIdsReportsTruncatedWhenTheBudgetIsHitFirst(): void
    {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('isAllowed')->willReturn(false);
        $ids = range(1, 100);

        $resolved = $this->selector($authorization, $this->objectMocks($ids), $ids, 10)->resolveIds('Product');

        self::assertSame([], $resolved['ids']);
        self::assertTrue($resolved['truncated']);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, AbstractObject>
     */
    private function objectMocks(array $ids): array
    {
        $map = [];
        foreach ($ids as $id) {
            $object = $this->createMock(AbstractObject::class);
            $object->method('getId')->willReturn($id);
            $object->method('getKey')->willReturn('object-' . $id);
            $map[$id] = $object;
        }

        return $map;
    }

    /**
     * @param array<int, AbstractObject> $objectsById
     * @param list<int>                  $windowIds
     */
    private function selector(ElementAuthorization $authorization, array $objectsById, array $windowIds, int $budget = 5000): VisibleObjectSelector
    {
        return new class ($authorization, $budget, $objectsById, $windowIds) extends VisibleObjectSelector {
            /**
             * @param array<int, AbstractObject> $objectsById
             * @param list<int>                  $windowIds
             */
            public function __construct(
                ElementAuthorization $authorization,
                int $budget,
                private readonly array $objectsById,
                private readonly array $windowIds,
            ) {
                parent::__construct($authorization, $budget);
            }

            protected function listObjectIds(string $className, int $offset, int $limit): array
            {
                return array_slice($this->windowIds, $offset, $limit);
            }

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->objectsById[$id] ?? null;
            }
        };
    }
}
