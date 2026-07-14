<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Service\DuplicateReferenceRepointer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(DuplicateReferenceRepointer::class)]
class DuplicateReferenceRepointerTest extends TestCase
{
    private function asset(int $id, string $path = '/p/x.jpg'): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn($id);
        $asset->method('getRealFullPath')->willReturn($path);

        return $asset;
    }

    /**
     * A repointer whose every Pimcore-touching seam is stubbed, so the test runs without a kernel.
     * loadAsset returns the supplied mocks (null models a deleted asset). The object's fields are
     * described by $relationFields/$wysiwygFields with current values in $values; $stillReferences is
     * the post-rewrite dependency verdict.
     *
     * @param array<int, array{id: int, type: string}> $requiredBy
     * @param list<array{0: string, 1: string}>        $relationFields [name, type]
     * @param list<string>                             $wysiwygFields
     * @param array<string, mixed>                     $values
     * @param \ArrayObject<int, string>                $writes  appended "name=token" on each set
     * @param \ArrayObject<int, int>                   $saved   appended on each guarded save
     */
    private function repointer(
        array $requiredBy,
        ?Asset $fromAsset = null,
        ?Asset $toAsset = null,
        ?Concrete $object = null,
        array $relationFields = [],
        array $wysiwygFields = [],
        array $values = [],
        bool $stillReferences = false,
        ?\ArrayObject $writes = null,
        ?\ArrayObject $saved = null,
    ): DuplicateReferenceRepointer {
        $writes ??= new \ArrayObject();
        $saved ??= new \ArrayObject();

        return new class (
            $requiredBy, $fromAsset, $toAsset, $object, $relationFields, $wysiwygFields, $values,
            $stillReferences, $writes, $saved,
        ) extends DuplicateReferenceRepointer {
            /**
             * @param array<int, array{id: int, type: string}> $requiredBy
             * @param list<array{0: string, 1: string}>        $relationFields
             * @param list<string>                             $wysiwygFields
             * @param array<string, mixed>                     $values
             * @param \ArrayObject<int, string>                $writes
             * @param \ArrayObject<int, int>                   $saved
             */
            public function __construct(
                private readonly array $requiredBy,
                private readonly ?Asset $fromAsset,
                private readonly ?Asset $toAsset,
                private readonly ?Concrete $object,
                private readonly array $relationFields,
                private readonly array $wysiwygFields,
                private array $values,
                private readonly bool $stillReferences,
                private readonly \ArrayObject $writes,
                private readonly \ArrayObject $saved,
            ) {
                parent::__construct(new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore())), new NullLogger(), (new \ReflectionClass(\Doctrine\DBAL\Connection::class))->newInstanceWithoutConstructor());
            }

            protected function loadAsset(int $id): ?Asset
            {
                return $id >= 100 ? $this->toAsset : $this->fromAsset;
            }

            protected function requiredBy(int $assetId, int $offset, int $limit): array
            {
                return $offset === 0 ? $this->requiredBy : [];
            }

            protected function loadObject(int $id): ?Concrete
            {
                return $this->object;
            }

            protected function relationFieldDefs(Concrete $object): array
            {
                return $this->relationFields;
            }

            protected function wysiwygFieldNames(Concrete $object): array
            {
                return $this->wysiwygFields;
            }

            protected function fieldValue(Concrete $object, string $name): mixed
            {
                return $this->values[$name] ?? null;
            }

            protected function setFieldValue(Concrete $object, string $name, mixed $value): void
            {
                $this->values[$name] = $value;
                $token = $value instanceof Asset ? (string) $value->getId() : (is_string($value) ? 'html' : 'arr');
                $this->writes->append($name . '=' . $token);
            }

            protected function saveObject(Concrete $object): void
            {
                $this->saved->append(1);
            }

            protected function objectStillReferences(int $objectId, int $fromAssetId): bool
            {
                return $this->stillReferences;
            }

            public function exposeReplace(string $type, mixed $value, int $fromId, Asset $to): array
            {
                return $this->replaceAssetReference($type, $value, $fromId, $to);
            }

            public function exposeHtml(string $html, string $fromPath, string $toPath, int $fromId, int $toId): array
            {
                return $this->replacePathInHtml($html, $fromPath, $toPath, $fromId, $toId);
            }
        };
    }

    #[Test]
    public function replacesASingleAssetRelation(): void
    {
        $to = $this->asset(105);
        [$changed, $new] = $this->repointer([])->exposeReplace('manyToOneRelation', $this->asset(9), 9, $to);

        self::assertTrue($changed);
        self::assertSame($to, $new);
    }

    #[Test]
    public function replacesAnImageRelation(): void
    {
        $to = $this->asset(105);
        [$changed, $new] = $this->repointer([])->exposeReplace('image', $this->asset(9), 9, $to);

        self::assertTrue($changed);
        self::assertSame($to, $new);
    }

    #[Test]
    public function leavesANonMatchingSingleRelation(): void
    {
        [$changed] = $this->repointer([])->exposeReplace('manyToOneRelation', $this->asset(8), 9, $this->asset(105));

        self::assertFalse($changed);
    }

    #[Test]
    public function replacesTheMatchingEntryInAManyToManyArray(): void
    {
        $keep = $this->asset(2);
        $to = $this->asset(105);
        [$changed, $new] = $this->repointer([])->exposeReplace('manyToManyRelation', [$keep, $this->asset(9)], 9, $to);

        self::assertTrue($changed);
        self::assertSame([$keep, $to], $new);
    }

    #[Test]
    public function dedupesWhenTheCanonicalIsAlreadyPresentInTheArray(): void
    {
        $to = $this->asset(105);
        [$changed, $new] = $this->repointer([])->exposeReplace('manyToManyRelation', [$to, $this->asset(9)], 9, $to);

        self::assertTrue($changed);
        self::assertCount(1, $new);
        self::assertSame($to, $new[0]);
    }

    #[Test]
    public function leavesUnhandledRelationTypesUntouched(): void
    {
        [$changed] = $this->repointer([])->exposeReplace('advancedManyToManyRelation', [], 9, $this->asset(105));

        self::assertFalse($changed);
    }

    #[Test]
    public function rewritesBothThePathAndTheEmbeddedIdInWysiwyg(): void
    {
        $html = 'see <a href="/copy.jpg">x</a> and <img pimcore_id="9" pimcore_type="asset" src="/copy.jpg">';
        [$changed, $new] = $this->repointer([])->exposeHtml($html, '/copy.jpg', '/canonical.jpg', 9, 105);

        self::assertTrue($changed);
        self::assertStringContainsString('/canonical.jpg', $new);
        self::assertStringContainsString('pimcore_id="105"', $new);
        self::assertStringNotContainsString('/copy.jpg', $new);
    }

    #[Test]
    public function leavesNonAssetPimcoreIdsUntouched(): void
    {
        $html = '<a pimcore_type="object" pimcore_id="9">object</a>'
            . '<a pimcore_id="9" pimcore_type="document">document</a>'
            . '<img pimcore_type="asset" pimcore_id="9">';

        [$changed, $new] = $this->repointer([])->exposeHtml($html, '/copy.jpg', '/canonical.jpg', 9, 105);

        self::assertTrue($changed);
        self::assertStringContainsString('pimcore_type="object" pimcore_id="9"', $new);
        self::assertStringContainsString('pimcore_id="9" pimcore_type="document"', $new);
        self::assertStringContainsString('pimcore_type="asset" pimcore_id="105"', $new);
    }

    #[Test]
    public function abortsWhenTheCanonicalAssetNoLongerExists(): void
    {
        $report = $this->repointer([], fromAsset: $this->asset(9, '/copy.jpg'), toAsset: null)->repoint(9, 105);

        self::assertFalse($report->fullyRepointed);
        self::assertSame(0, $report->repointedObjects);
    }

    #[Test]
    public function blocksNonObjectReferenceTypes(): void
    {
        $report = $this->repointer(
            [['id' => 12, 'type' => 'document']],
            fromAsset: $this->asset(9, '/copy.jpg'),
            toAsset: $this->asset(105, '/canonical.jpg'),
        )->repoint(9, 105);

        self::assertSame(0, $report->repointedObjects);
        self::assertCount(1, $report->blocked);
        self::assertStringContainsString('document', $report->blocked[0]);
    }

    #[Test]
    public function repointsAnObjectRelationFieldAndSavesGuarded(): void
    {
        $writes = new \ArrayObject();
        $saved = new \ArrayObject();
        $report = $this->repointer(
            [['id' => 42, 'type' => 'object']],
            fromAsset: $this->asset(9, '/copy.jpg'),
            toAsset: $this->asset(105, '/canonical.jpg'),
            object: $this->createMock(Concrete::class),
            relationFields: [['hero', 'manyToOneRelation']],
            values: ['hero' => $this->asset(9)],
            writes: $writes,
            saved: $saved,
        )->repoint(9, 105);

        self::assertSame(1, $report->repointedObjects);
        self::assertTrue($report->fullyRepointed);
        self::assertSame(['hero=105'], $writes->getArrayCopy());
        self::assertSame([1], $saved->getArrayCopy());
    }

    #[Test]
    public function blocksWhenTheObjectStillReferencesTheCopyAfterRewrite(): void
    {
        $report = $this->repointer(
            [['id' => 42, 'type' => 'object']],
            fromAsset: $this->asset(9, '/copy.jpg'),
            toAsset: $this->asset(105, '/canonical.jpg'),
            object: $this->createMock(Concrete::class),
            relationFields: [['hero', 'manyToOneRelation']],
            values: ['hero' => $this->asset(9)],
            stillReferences: true,
        )->repoint(9, 105);

        self::assertFalse($report->fullyRepointed);
        self::assertStringContainsString('42', $report->blocked[0]);
    }

    #[Test]
    public function stillReferencesQueryIsTargetedAndBounded(): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'dbname' => 'x', 'user' => 'x', 'password' => 'x', 'serverVersion' => '8.0.0',
        ]);
        $repointer = new DuplicateReferenceRepointer(
            new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore())),
            new NullLogger(),
            $connection,
        );

        $method = new \ReflectionMethod(DuplicateReferenceRepointer::class, 'stillReferencesQuery');
        /** @var \Doctrine\DBAL\Query\QueryBuilder $qb */
        $qb = $method->invoke($repointer, 42, 9);
        $sql = $qb->getSQL();

        self::assertStringContainsString('dependencies', $sql);
        self::assertStringContainsString('sourcetype = :sourceType', $sql);
        self::assertStringContainsString('sourceid = :objectId', $sql);
        self::assertStringContainsString('targettype = :targetType', $sql);
        self::assertStringContainsString('targetid = :assetId', $sql);
        self::assertSame(1, $qb->getMaxResults(), 'must early-exit with LIMIT 1 instead of scanning all dependencies');
    }
}
