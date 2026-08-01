<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Exception\NotPermittedException;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\DuplicateReferenceRepointer;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\ObjectSaveDrain;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\AbstractElement;
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
        bool $isObjectAuthorized = true,
        ?LoopGuard $loopGuard = null,
        ?OrganizeDispatcherInterface $dispatcher = null,
    ): DuplicateReferenceRepointer {
        $writes ??= new \ArrayObject();
        $saved ??= new \ArrayObject();

        return new class (
            $requiredBy, $fromAsset, $toAsset, $object, $relationFields, $wysiwygFields, $values,
            $stillReferences, $writes, $saved, $this->createMock(ElementAuthorization::class),
            $isObjectAuthorized, $loopGuard ?? new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore())),
            $dispatcher ?? $this->createMock(OrganizeDispatcherInterface::class),
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
                ElementAuthorization $authorization,
                private readonly bool $isObjectAuthorized,
                LoopGuard $loopGuard,
                OrganizeDispatcherInterface $dispatcher,
            ) {
                parent::__construct($loopGuard, new NullLogger(), (new \ReflectionClass(\Doctrine\DBAL\Connection::class))->newInstanceWithoutConstructor(), $authorization, new ObjectSaveDrain($loopGuard, $dispatcher, new NullLogger()));
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

            protected function referrerFingerprint(string $type, int $id, AbstractElement $element): string
            {
                return hash('sha256', $type . ':' . $id . ':v1');
            }

            protected function objectAllows(Concrete $object, string $permission): bool
            {
                TestCase::assertContains($permission, ['view', 'publish']);

                return $this->isObjectAuthorized;
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
    public function repointDrainsACoalescedSaveAfterTheGuardedSave(): void
    {
        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('acquireObject')->willReturn(true);
        $loopGuard->method('isObjectDirty')->with(42)->willReturn(true);
        $loopGuard->expects(self::once())->method('clearObjectDirty')->with(42);
        $dispatcher = $this->createMock(OrganizeDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatchObject')->with(42, TriggerType::ObjectSave, self::anything());

        $this->repointer(
            [['id' => 42, 'type' => 'object']],
            fromAsset: $this->asset(9, '/copy.jpg'),
            toAsset: $this->asset(105, '/canonical.jpg'),
            object: $this->createMock(Concrete::class),
            relationFields: [['hero', 'manyToOneRelation']],
            values: ['hero' => $this->asset(9)],
            loopGuard: $loopGuard,
            dispatcher: $dispatcher,
        )->repoint(9, 105);
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
    public function blocksBeforeRewritingAnObjectOutsideTheActorWorkspace(): void
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
            isObjectAuthorized: false,
        )->repoint(9, 105);

        self::assertFalse($report->fullyRepointed);
        self::assertStringContainsString('outside the actor workspace', $report->blocked[0]);
        self::assertSame([], $writes->getArrayCopy());
        self::assertSame([], $saved->getArrayCopy());
    }

    #[Test]
    public function preflightSnapshotsEveryObjectReferrerBeforeMutation(): void
    {
        $saved = new \ArrayObject();
        $preflight = $this->repointer(
            [['id' => 42, 'type' => 'object']],
            fromAsset: $this->asset(9, '/copy.jpg'),
            toAsset: $this->asset(105, '/canonical.jpg'),
            object: $this->createMock(Concrete::class),
            saved: $saved,
        )->preflight(9, 105, 'publish');

        self::assertSame([], $preflight->blocked);
        self::assertCount(1, $preflight->referrers);
        self::assertSame('object:42', $preflight->referrers[0]->key());
        self::assertSame(hash('sha256', 'object:42:v1'), $preflight->referrers[0]->fingerprint);
        self::assertSame([], $saved->getArrayCopy());
    }

    #[Test]
    public function preflightRejectsAnUnauthorizedReferrerBeforeMutation(): void
    {
        $saved = new \ArrayObject();
        $repointer = $this->repointer(
            [['id' => 42, 'type' => 'object']],
            fromAsset: $this->asset(9, '/copy.jpg'),
            toAsset: $this->asset(105, '/canonical.jpg'),
            object: $this->createMock(Concrete::class),
            saved: $saved,
            isObjectAuthorized: false,
        );

        $this->expectException(NotPermittedException::class);
        try {
            $repointer->preflight(9, 105, 'publish');
        } finally {
            self::assertSame([], $saved->getArrayCopy());
        }
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
            $this->createMock(ElementAuthorization::class),
            new ObjectSaveDrain(new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore())), $this->createMock(OrganizeDispatcherInterface::class), new NullLogger()),
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

    #[Test]
    public function blocksBeforeMutationWhenAnotherWorkerHoldsTheObjectLock(): void
    {
        $store = new InMemoryStore();
        $owner = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        $worker = new LoopGuard(new ArrayAdapter(), new LockFactory($store));
        self::assertTrue($owner->acquireObject(42));
        $saved = new \ArrayObject();

        $report = $this->repointer(
            [['id' => 42, 'type' => 'object']],
            fromAsset: $this->asset(9, '/copy.jpg'),
            toAsset: $this->asset(105, '/canonical.jpg'),
            object: $this->createMock(Concrete::class),
            relationFields: [['hero', 'manyToOneRelation']],
            values: ['hero' => $this->asset(9)],
            saved: $saved,
            loopGuard: $worker,
        )->repoint(9, 105);

        self::assertFalse($report->fullyRepointed);
        self::assertStringContainsString('another job', $report->blocked[0]);
        self::assertSame([], $saved->getArrayCopy());
    }
}
