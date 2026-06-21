<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Service\EmptyFolderSweepService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;

#[CoversClass(EmptyFolderSweepService::class)]
class EmptyFolderSweepServiceTest extends TestCase
{
    private function folder(int $id, bool $hasChildren, bool $allowed): Asset\Folder
    {
        $folder = $this->createMock(Asset\Folder::class);
        $folder->method('getId')->willReturn($id);
        $folder->method('hasChildren')->willReturn($hasChildren);
        $folder->method('isAllowed')->with('delete')->willReturn($allowed);
        $folder->method('getRealFullPath')->willReturn('/folder/' . $id);

        return $folder;
    }

    /**
     * @param array<int, ?Asset\Folder>                  $foldersById
     * @param \ArrayObject<int, int>                     $deleted
     * @param list<array{id: int, full_path: string}>    $rows
     */
    private function service(array $foldersById = [], ?\ArrayObject $deleted = null, array $rows = []): EmptyFolderSweepService
    {
        $deleted ??= new \ArrayObject();

        return new class ($foldersById, $deleted, $rows) extends EmptyFolderSweepService {
            /**
             * @param array<int, ?Asset\Folder>               $foldersById
             * @param \ArrayObject<int, int>                  $deleted
             * @param list<array{id: int, full_path: string}> $rows
             */
            public function __construct(private readonly array $foldersById, private readonly \ArrayObject $deleted, private readonly array $rows)
            {
                parent::__construct((new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor(), new NullLogger());
            }

            protected function loadFolder(int $id): ?Asset\Folder
            {
                return $this->foldersById[$id] ?? null;
            }

            protected function deleteFolder(Asset\Folder $folder): void
            {
                $this->deleted->append((int) $folder->getId());
            }

            protected function listEmptyFolderRows(?string $root, int $offset, int $limit): array
            {
                return array_slice($this->rows, $offset, $limit);
            }
        };
    }

    #[Test]
    public function deletesChildlessPermittedFolders(): void
    {
        $deleted = new \ArrayObject();
        $result = $this->service([5 => $this->folder(5, hasChildren: false, allowed: true)], $deleted)->deleteEmpty([5]);

        self::assertSame(1, $result['deleted']);
        self::assertSame([5], $deleted->getArrayCopy());
    }

    #[Test]
    public function skipsAFolderThatGainedChildrenSinceTheListing(): void
    {
        $deleted = new \ArrayObject();
        $result = $this->service([5 => $this->folder(5, hasChildren: true, allowed: true)], $deleted)->deleteEmpty([5]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function failsWhenTheWorkspaceAclDeniesDeletion(): void
    {
        $deleted = new \ArrayObject();
        $result = $this->service([5 => $this->folder(5, hasChildren: false, allowed: false)], $deleted)->deleteEmpty([5]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['failed']);
        self::assertArrayHasKey(5, $result['errors']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function neverDeletesTheAssetTreeRoot(): void
    {
        $deleted = new \ArrayObject();
        $result = $this->service([], $deleted)->deleteEmpty([1]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $deleted->getArrayCopy());
    }

    #[Test]
    public function findEmptyMapsRowsToIdAndPath(): void
    {
        $found = $this->service(rows: [['id' => 5, 'full_path' => '/a/b'], ['id' => 9, 'full_path' => '/c/d']])->findEmpty();

        self::assertSame([['id' => 5, 'path' => '/a/b'], ['id' => 9, 'path' => '/c/d']], $found['items']);
    }
}
