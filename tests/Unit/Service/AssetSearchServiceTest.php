<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\DriverManager;
use Oronts\AssetPilotBundle\Service\AssetSearchService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AssetSearchService::class)]
class AssetSearchServiceTest extends TestCase
{
    private function service(): AssetSearchService
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('CREATE TABLE assets (id INTEGER PRIMARY KEY, path TEXT, filename TEXT, type TEXT, mimetype TEXT, creationDate INTEGER, modificationDate INTEGER)');
        $conn->executeStatement('CREATE TABLE properties (cid INTEGER, ctype TEXT, name TEXT, data TEXT)');
        $conn->executeStatement('CREATE TABLE dependencies (sourceid INTEGER, sourcetype TEXT, targetid INTEGER, targettype TEXT)');
        foreach ([[1, 'a.png', 'image'], [2, 'b.pdf', 'document'], [3, 'c.jpg', 'image'], [4, 'd.png', 'image']] as [$id, $fn, $type]) {
            $conn->insert('assets', ['id' => $id, 'path' => '/x/', 'filename' => $fn, 'type' => $type, 'mimetype' => '', 'creationDate' => 0, 'modificationDate' => $id]);
        }
        // An object references assets 1 and 3; a document references asset 4 (any element counts as a
        // reference, matching UnusedAssetFinder); asset 2 is unreferenced.
        $conn->insert('dependencies', ['sourceid' => 100, 'sourcetype' => 'object', 'targetid' => 1, 'targettype' => 'asset']);
        $conn->insert('dependencies', ['sourceid' => 100, 'sourcetype' => 'object', 'targetid' => 3, 'targettype' => 'asset']);
        $conn->insert('dependencies', ['sourceid' => 200, 'sourcetype' => 'document', 'targetid' => 4, 'targettype' => 'asset']);

        return new class ($conn, new NullLogger()) extends AssetSearchService {
            protected function fileSize(string $fullPath): int
            {
                return 0;
            }
        };
    }

    /** @param array{items: array<int, array<string, mixed>>} $result @return list<int> */
    private function ids(array $result): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $result['items']);
        sort($ids);

        return $ids;
    }

    #[Test]
    public function filtersByReferencedState(): void
    {
        $service = $this->service();

        self::assertSame([1, 3, 4], $this->ids($service->search(['referenced' => 'referenced'])), 'object- and document-referenced both count');
        self::assertSame([2], $this->ids($service->search(['referenced' => 'unreferenced'])));
        self::assertSame([1, 2, 3, 4], $this->ids($service->search([])), 'no filter returns every asset');
    }

    #[Test]
    public function filtersByExtension(): void
    {
        $service = $this->service();

        self::assertSame([2], $this->ids($service->search(['extension' => 'pdf'])));
        self::assertSame([1, 4], $this->ids($service->search(['extension' => '.png'])), 'a leading dot is tolerated');
    }
}
