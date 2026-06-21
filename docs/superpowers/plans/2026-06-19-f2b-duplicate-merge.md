# F2b Duplicate Merge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. TDD every step: failing test first, watch it fail, minimal code, watch it pass, commit.

**Goal:** Consolidate byte-identical duplicate assets onto one canonical asset by re-pointing references (typed relation fields + WYSIWYG asset paths), then disposing of the now-redundant copies via a configurable, developer-extensible merge strategy.

**Architecture:** A shared, non-pluggable re-point engine (`DuplicateReferenceRepointer`) rewrites references from a copy onto the canonical asset and reports any surface it could not safely rewrite (documents, asset-to-asset refs, nested bricks/blocks/fieldcollections, advanced metadata relations, hotspotimage). Disposition is a pluggable Strategy seam (`DuplicateMergeStrategyInterface`, tagged service, resolved by name) so each project picks `quarantine` / `delete` / `isolate` or ships its own. `DuplicateMergeService` orchestrates: pick canonical, repoint every other copy, hand each copy to the active strategy. A copy is disposed ONLY when its references were fully repointed (zero blocked surfaces) — a still-partially-referenced copy is left and reported. Every owner save is `LoopGuard`-wrapped; both assets are re-verified before any write.

**Tech Stack:** PHP 8.4, Pimcore 12 (`DataObject\Concrete`, `Asset`, `Dependency::getRequiredBy`, relation field definitions), Symfony 7 (tagged services), PHPUnit 11.

**Non-negotiables (from .claude/CLAUDE.md):**
- Destructive op → re-verify state + guard + Admin permission + test.
- Owner saves inside the pipeline → `LoopGuard` (markProcessing → save → markRecentlyMoved → unmark in finally).
- No SQL identifier interpolation. No client-specific logic. Surgical diffs. No over-commenting.
- A copy is NEVER disposed if any reference to it could not be repointed (fail-safe).

---

## File Structure

**Create:**
- `src/Merge/DuplicateMergeStrategyInterface.php` — the seam: `name(): string`, `disposeCopy(int $copyId, RepointReport $report): CopyDisposition`.
- `src/Merge/CopyDisposition.php` — readonly DTO: outcome enum + reason for one copy.
- `src/Merge/RepointReport.php` — readonly DTO: `fromAssetId`, `toAssetId`, `repointedObjects`, `list<string> blocked` (human reasons), `bool fullyRepointed` (= `blocked === []`).
- `src/Merge/MergeOutcome.php` — readonly DTO: per-group result (checksum, canonicalId, list of per-copy CopyDisposition + RepointReport, counts).
- `src/Enum/DispositionOutcome.php` — `Quarantined`, `Deleted`, `LeftReferenced`, `LeftError`, `Skipped`.
- `src/Service/DuplicateReferenceRepointer.php` — the shared engine.
- `src/Merge/Strategy/RepointAndQuarantineStrategy.php` — name `quarantine`.
- `src/Merge/Strategy/RepointAndDeleteStrategy.php` — name `delete`.
- `src/Merge/Strategy/IsolateUnreferencedStrategy.php` — name `isolate` (never repoints; only disposes copies that were already unreferenced).
- `src/Service/DuplicateMergeService.php` — orchestrator + strategy resolution by name.
- `src/Command/MergeDuplicatesCommand.php` — `asset-pilot:merge-duplicates`.
- Tests mirroring each under `tests/Unit/...`.

**Modify:**
- `src/Resources/config/services.yaml` — `_instanceof` tag for the strategy seam; wire repointer, strategies, service, command.
- `src/DependencyInjection/Configuration.php` — `duplicates.merge_strategy` (default `quarantine`).
- `src/Controller/Api/DuplicatesController.php` — add `POST /duplicates/merge` (Admin).

---

## Cluster 1 — Strategy seam + orchestrator skeleton (no repointing yet)

### Task 1: DTOs and disposition enum

**Files:**
- Create: `src/Enum/DispositionOutcome.php`, `src/Merge/RepointReport.php`, `src/Merge/CopyDisposition.php`, `src/Merge/MergeOutcome.php`
- Test: `tests/Unit/Merge/RepointReportTest.php`

- [ ] **Step 1: Failing test** — `RepointReport` derives `fullyRepointed` from emptiness of `blocked`.

```php
<?php
declare(strict_types=1);
namespace Oronts\AssetPilotBundle\Tests\Unit\Merge;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
#[CoversClass(RepointReport::class)]
class RepointReportTest extends TestCase
{
    #[Test]
    public function isFullyRepointedOnlyWhenNothingIsBlocked(): void
    {
        self::assertTrue((new RepointReport(5, 9, 3, []))->fullyRepointed);
        self::assertFalse((new RepointReport(5, 9, 3, ['document 12 references the copy']))->fullyRepointed);
    }
}
```

- [ ] **Step 2: Run** `vendor/bin/phpunit --filter RepointReportTest` → FAIL (class missing).
- [ ] **Step 3: Implement the DTOs.**

```php
// src/Enum/DispositionOutcome.php
<?php
declare(strict_types=1);
namespace Oronts\AssetPilotBundle\Enum;
enum DispositionOutcome: string
{
    case Quarantined = 'quarantined';
    case Deleted = 'deleted';
    case LeftReferenced = 'left_referenced';
    case LeftError = 'left_error';
    case Skipped = 'skipped';
}
```

```php
// src/Merge/RepointReport.php
<?php
declare(strict_types=1);
namespace Oronts\AssetPilotBundle\Merge;
/** What the repointer did for one copy, and what it could not safely rewrite. */
readonly class RepointReport
{
    /** @param list<string> $blocked human-readable reasons a reference could not be repointed */
    public function __construct(
        public int $fromAssetId,
        public int $toAssetId,
        public int $repointedObjects,
        public array $blocked,
    ) {}

    public bool $fullyRepointed { get => $this->blocked === []; }
}
```
(If the property-hook syntax is rejected by the toolchain, use a `public readonly bool $fullyRepointed` set in the constructor body from `$blocked === []`.)

```php
// src/Merge/CopyDisposition.php
<?php
declare(strict_types=1);
namespace Oronts\AssetPilotBundle\Merge;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
readonly class CopyDisposition
{
    public function __construct(
        public int $copyId,
        public DispositionOutcome $outcome,
        public string $reason = '',
    ) {}
}
```

```php
// src/Merge/MergeOutcome.php
<?php
declare(strict_types=1);
namespace Oronts\AssetPilotBundle\Merge;
readonly class MergeOutcome
{
    /** @param list<CopyDisposition> $dispositions */
    public function __construct(
        public string $checksum,
        public int $canonicalId,
        public array $dispositions,
    ) {}
}
```

- [ ] **Step 4: Run** the test → PASS.
- [ ] **Step 5: Commit** `git add src/Enum/DispositionOutcome.php src/Merge/RepointReport.php src/Merge/CopyDisposition.php src/Merge/MergeOutcome.php tests/Unit/Merge/RepointReportTest.php && git commit --author="Refaat Al Ktifan <Refaat@alktifan.com>" -m "Add duplicate-merge DTOs and disposition enum"`

### Task 2: Strategy seam interface + `IsolateUnreferencedStrategy`

**Files:**
- Create: `src/Merge/DuplicateMergeStrategyInterface.php`, `src/Merge/Strategy/IsolateUnreferencedStrategy.php`
- Test: `tests/Unit/Merge/Strategy/IsolateUnreferencedStrategyTest.php`

The interface:
```php
// src/Merge/DuplicateMergeStrategyInterface.php
<?php
declare(strict_types=1);
namespace Oronts\AssetPilotBundle\Merge;
interface DuplicateMergeStrategyInterface
{
    /** Stable key used to select this strategy from config / the API (e.g. 'quarantine'). */
    public function name(): string;

    /**
     * Dispose of one duplicate copy after the repointer has run for it. MUST NOT destroy a copy whose
     * references were not fully repointed ($report->fullyRepointed === false).
     */
    public function disposeCopy(int $copyId, RepointReport $report): CopyDisposition;
}
```

`IsolateUnreferencedStrategy` ignores the repoint report (it is the "don't repoint" policy: the service runs it WITHOUT repointing — see Task 5) and quarantines a copy only when it is already unreferenced. It is injected with `AssetDependencyResolver` + `QuarantineService`.

- [ ] **Step 1: Failing test** — an unreferenced copy is quarantined; a referenced copy is left.

```php
<?php
declare(strict_types=1);
namespace Oronts\AssetPilotBundle\Tests\Unit\Merge\Strategy;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Merge\Strategy\IsolateUnreferencedStrategy;
use Oronts\AssetPilotBundle\Service\AssetDependencyResolver;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
#[CoversClass(IsolateUnreferencedStrategy::class)]
class IsolateUnreferencedStrategyTest extends TestCase
{
    #[Test]
    public function quarantinesAnUnreferencedCopy(): void
    {
        $resolver = $this->createMock(AssetDependencyResolver::class);
        $resolver->method('dependentObjectIds')->with(9, 1)->willReturn([]);
        $quarantine = $this->createMock(QuarantineService::class);
        $quarantine->expects(self::once())->method('quarantine')->with([9])
            ->willReturn(['quarantined' => 1, 'failed' => 0, 'errors' => []]);

        $d = (new IsolateUnreferencedStrategy($resolver, $quarantine))
            ->disposeCopy(9, new RepointReport(9, 5, 0, []));

        self::assertSame(DispositionOutcome::Quarantined, $d->outcome);
    }

    #[Test]
    public function leavesAStillReferencedCopy(): void
    {
        $resolver = $this->createMock(AssetDependencyResolver::class);
        $resolver->method('dependentObjectIds')->with(9, 1)->willReturn([42]);
        $quarantine = $this->createMock(QuarantineService::class);
        $quarantine->expects(self::never())->method('quarantine');

        $d = (new IsolateUnreferencedStrategy($resolver, $quarantine))
            ->disposeCopy(9, new RepointReport(9, 5, 0, []));

        self::assertSame(DispositionOutcome::LeftReferenced, $d->outcome);
    }
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**

```php
// src/Merge/Strategy/IsolateUnreferencedStrategy.php
<?php
declare(strict_types=1);
namespace Oronts\AssetPilotBundle\Merge\Strategy;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Service\AssetDependencyResolver;
use Oronts\AssetPilotBundle\Service\QuarantineService;

/** Never repoints: quarantines only copies that are already unreferenced; leaves the rest reported. */
class IsolateUnreferencedStrategy implements DuplicateMergeStrategyInterface
{
    public function __construct(
        protected readonly AssetDependencyResolver $dependencies,
        protected readonly QuarantineService $quarantine,
    ) {}

    public function name(): string
    {
        return 'isolate';
    }

    public function disposeCopy(int $copyId, RepointReport $report): CopyDisposition
    {
        if ($this->dependencies->dependentObjectIds($copyId, 1) !== []) {
            return new CopyDisposition($copyId, DispositionOutcome::LeftReferenced, 'still referenced by at least one object');
        }
        $result = $this->quarantine->quarantine([$copyId]);
        if (($result['quarantined'] ?? 0) > 0) {
            return new CopyDisposition($copyId, DispositionOutcome::Quarantined);
        }

        return new CopyDisposition($copyId, DispositionOutcome::LeftError, 'quarantine did not move the copy');
    }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `... -m "Add duplicate-merge strategy seam + isolate strategy"`

### Task 3: `DuplicateMergeService` orchestration (strategy resolution + canonical selection)

**Files:**
- Create: `src/Service/DuplicateMergeService.php`
- Test: `tests/Unit/Service/DuplicateMergeServiceTest.php`

Resolves a strategy by name from an injected `iterable` (built into a map at construction). Canonical = lowest asset id in the group (oldest, deterministic) unless an explicit canonical id is passed and is a member. `merge()` runs the repointer for every NON-canonical copy then hands each to the strategy. The repointer is a protected seam so the unit test stubs it.

- [ ] **Step 1: Failing test** — merges a group: canonical chosen, every copy repointed + disposed; unknown strategy throws.

```php
<?php
declare(strict_types=1);
namespace Oronts\AssetPilotBundle\Tests\Unit\Service;
use Oronts\AssetPilotBundle\Enum\DispositionOutcome;
use Oronts\AssetPilotBundle\Merge\CopyDisposition;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\RepointReport;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use Oronts\AssetPilotBundle\Service\DuplicateReferenceRepointer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
#[CoversClass(DuplicateMergeService::class)]
class DuplicateMergeServiceTest extends TestCase
{
    private function strategy(string $name): DuplicateMergeStrategyInterface
    {
        return new class ($name) implements DuplicateMergeStrategyInterface {
            public function __construct(private readonly string $n) {}
            public function name(): string { return $this->n; }
            public function disposeCopy(int $copyId, RepointReport $report): CopyDisposition
            {
                return new CopyDisposition($copyId, DispositionOutcome::Quarantined);
            }
        };
    }

    private function service(DuplicateReferenceRepointer $repointer, DuplicateGroup $group): DuplicateMergeService
    {
        return new class ([$this->strategy('quarantine')], $repointer, new NullLogger(), $group) extends DuplicateMergeService {
            public function __construct(iterable $s, DuplicateReferenceRepointer $r, $l, private readonly DuplicateGroup $g)
            { parent::__construct($s, $r, $l, 'quarantine'); }
            protected function groupForChecksum(string $checksum): ?DuplicateGroup { return $this->g; }
        };
    }

    #[Test]
    public function repointsEveryCopyOntoTheLowestIdCanonicalAndDisposesThem(): void
    {
        $repointer = $this->createMock(DuplicateReferenceRepointer::class);
        // canonical is 3 (lowest); copies 7 and 9 are repointed onto it.
        $repointer->expects(self::exactly(2))->method('repoint')
            ->willReturnCallback(fn (int $from, int $to, bool $dry) => new RepointReport($from, $to, 1, []));

        $group = new DuplicateGroup('abc', 100, 3, [7, 3, 9]);
        $outcome = $this->service($repointer, $group)->merge('abc');

        self::assertSame(3, $outcome->canonicalId);
        self::assertCount(2, $outcome->dispositions);
        self::assertSame(DispositionOutcome::Quarantined, $outcome->dispositions[0]->outcome);
    }

    #[Test]
    public function rejectsAnUnknownStrategyName(): void
    {
        $group = new DuplicateGroup('abc', 100, 2, [1, 2]);
        $this->expectException(\InvalidArgumentException::class);
        $this->service($this->createMock(DuplicateReferenceRepointer::class), $group)->merge('abc', null, 'nope');
    }
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**

```php
// src/Service/DuplicateMergeService.php
<?php
declare(strict_types=1);
namespace Oronts\AssetPilotBundle\Service;
use Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface;
use Oronts\AssetPilotBundle\Merge\MergeOutcome;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates a duplicate merge: pick the canonical asset, repoint every other copy's references
 * onto it, then hand each copy to the configured disposition strategy. Detection (which assets share
 * a checksum) comes from DuplicateDetectionService; this service owns the destructive consolidation.
 */
class DuplicateMergeService
{
    /** @var array<string, DuplicateMergeStrategyInterface> */
    private array $strategies = [];

    /** @param iterable<DuplicateMergeStrategyInterface> $strategies */
    public function __construct(
        iterable $strategies,
        protected readonly DuplicateReferenceRepointer $repointer,
        protected readonly LoggerInterface $logger,
        protected readonly string $defaultStrategy = 'quarantine',
    ) {
        foreach ($strategies as $strategy) {
            $this->strategies[$strategy->name()] = $strategy;
        }
    }

    /** @return list<string> the names of every registered strategy (for the API/command to advertise) */
    public function availableStrategies(): array
    {
        return array_keys($this->strategies);
    }

    public function merge(string $checksum, ?int $canonicalId = null, ?string $strategyName = null, bool $dryRun = false): MergeOutcome
    {
        $strategy = $this->resolveStrategy($strategyName ?? $this->defaultStrategy);

        $group = $this->groupForChecksum($checksum);
        if ($group === null || count($group->assetIds) < 2) {
            return new MergeOutcome($checksum, 0, []);
        }

        $canonical = $this->pickCanonical($group, $canonicalId);
        $copies = array_values(array_filter($group->assetIds, static fn (int $id): bool => $id !== $canonical));

        $dispositions = [];
        foreach ($copies as $copyId) {
            $report = $this->repointer->repoint($copyId, $canonical, $dryRun);
            $dispositions[] = $strategy->disposeCopy($copyId, $report);
        }

        return new MergeOutcome($checksum, $canonical, $dispositions);
    }

    private function resolveStrategy(string $name): DuplicateMergeStrategyInterface
    {
        return $this->strategies[$name]
            ?? throw new \InvalidArgumentException(sprintf('Unknown duplicate-merge strategy "%s". Available: %s.', $name, implode(', ', $this->availableStrategies())));
    }

    private function pickCanonical(DuplicateGroup $group, ?int $canonicalId): int
    {
        if ($canonicalId !== null && in_array($canonicalId, $group->assetIds, true)) {
            return $canonicalId;
        }

        return min($group->assetIds);
    }

    protected function groupForChecksum(string $checksum): ?DuplicateGroup
    {
        // Implemented in Task 6 against DuplicateDetectionService; stubbed in tests.
        return null;
    }
}
```

- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `... -m "Add DuplicateMergeService orchestration + strategy resolution"`

---

## Cluster 2 — The re-point engine (the hard part)

### Task 4: `DuplicateReferenceRepointer` — relation fields + WYSIWYG, with blocked-surface reporting

**Files:**
- Create: `src/Service/DuplicateReferenceRepointer.php`
- Test: `tests/Unit/Service/DuplicateReferenceRepointerTest.php`

Design (all Pimcore-touching calls behind protected seams so the unit test runs kernel-free):
- `repoint(from, to, dryRun)`: re-verify both assets exist (seam `loadAsset`); if either missing → `RepointReport(from,to,0,['asset … no longer exists'])`. Page reverse dependencies (seam `requiredBy(from, offset, limit)` returns `list<array{id,type}>`). For each row: `type==='object'` → `repointObject`; `type==='document'|'asset'` → blocked reason. Count repointed objects. LoopGuard-wrap the per-object save (seam `saveObject`).
- `repointObject(objectId, from, to, dryRun)`: load object (seam); walk field definitions (seam `relationFields(object)` returns `list<array{name,type}>`, `wysiwygFields(object)`); for each relation field, read value (seam `fieldValue`), call `replaceAssetReference` (pure, unit-tested), if changed set value (seam `setFieldValue`); for each wysiwyg field, `replacePathInHtml` (pure). If the object has nested-structure fields (block/fieldcollection/objectbricks) → add a blocked reason (v1 does not rewrite nested). Return `[changed, list<blocked>]`.

The PURE replacement helpers are the testable core — write these tests first:

- [ ] **Step 1: Failing test for `replaceAssetReference`** (single, array, advanced metadata, no-op).

```php
// excerpt — full file in repo
#[Test]
public function replacesASingleAssetRelation(): void
{
    $to = $this->assetMock(5);
    [$changed, $new] = $this->repointer()->exposeReplace('manyToOneRelation', $this->assetMock(9), 9, $to);
    self::assertTrue($changed);
    self::assertSame($to, $new);
}
#[Test]
public function replacesTheMatchingEntryInAManyToManyArray(): void
{
    $keep = $this->assetMock(2); $to = $this->assetMock(5);
    [$changed, $new] = $this->repointer()->exposeReplace('manyToManyRelation', [$keep, $this->assetMock(9)], 9, $to);
    self::assertTrue($changed);
    self::assertSame([$keep, $to], $new);
}
#[Test]
public function dedupesWhenTheCanonicalIsAlreadyPresentInTheArray(): void
{
    $to = $this->assetMock(5);
    [$changed, $new] = $this->repointer()->exposeReplace('manyToManyRelation', [$to, $this->assetMock(9)], 9, $to);
    self::assertTrue($changed);
    self::assertSame([$to], $new); // canonical not duplicated
}
#[Test]
public function reportsAdvancedMetadataRelationsAsUnhandled(): void
{
    [$changed, $new, $blocked] = $this->repointer()->exposeReplaceWithBlock('advancedManyToManyRelation', [], 9, $this->assetMock(5));
    self::assertFalse($changed);
    self::assertTrue($blocked);
}
```

- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** `replaceAssetReference(string $type, mixed $value, int $fromId, Asset $to): array{0:bool,1:mixed}` and `replacePathInHtml(string $html, string $fromPath, string $toPath, int $fromId, int $toId): array{0:bool,1:string}`. Handled relation types: `image`, `manyToOneRelation`, `manyToManyRelation`. Unhandled (return changed=false + a blocked reason at the caller): `advancedManyToManyRelation`, `hotspotimage`, `manyToManyObjectRelation` (objects only, never an asset — skip silently). For `manyToManyRelation`: rebuild the array replacing any element with `getId()===fromId && instanceof Asset` by `$to`, then drop a second `$to` if the canonical was already in the list. For WYSIWYG: `str_replace` the escaped `$fromPath`→`$toPath` AND `pimcore_id="{fromId}"`→`pimcore_id="{toId}"`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Failing test for `repoint`** orchestration (object repointed; document ref blocked; missing asset aborts) using the seams.
- [ ] **Step 6: Run** → FAIL → implement `repoint`/`repointObject` with `LoopGuard` around `saveObject` → PASS.
- [ ] **Step 7: Commit** `... -m "Add DuplicateReferenceRepointer (relation + WYSIWYG repoint, blocked-surface reporting)"`

### Task 5: `RepointAndQuarantine` + `RepointAndDelete` strategies

**Files:**
- Create: `src/Merge/Strategy/RepointAndQuarantineStrategy.php`, `src/Merge/Strategy/RepointAndDeleteStrategy.php`
- Test: matching tests.

Both: if `!$report->fullyRepointed` → `CopyDisposition(LeftReferenced, 'N references could not be repointed: …')` and DO NOT dispose. Else quarantine (`QuarantineService::quarantine([$copyId])`) or delete (seam `deleteAsset($copyId)`, re-verifying the asset is unreferenced via `AssetDependencyResolver` first — destructive guard). Delete strategy re-verifies `dependentObjectIds($copyId,1) === []` before deleting; if not empty → `LeftReferenced`.

- [ ] TDD each (fully-repointed → quarantined/deleted; partially-blocked → left; delete with lingering ref → left). Commit `... -m "Add repoint+quarantine and repoint+delete merge strategies"`.

---

## Cluster 3 — Wiring, API, command, config

### Task 6: Config + services.yaml + `groupForChecksum`

**Files:**
- Modify: `src/DependencyInjection/Configuration.php` (add under the `duplicates`/root tree: `->scalarNode('merge_strategy')->defaultValue('quarantine')->end()` inside a `duplicates` arrayNode; add `duplicates` node if absent), `src/Resources/config/services.yaml`, `src/Service/DuplicateMergeService.php` (`groupForChecksum` queries `DuplicateDetectionService` — add a `groupForChecksum(string): ?DuplicateGroup` to that service that returns the group for one checksum, or reuse `findDuplicates` filtered).

services.yaml:
```yaml
    _instanceof:
        # … existing …
        Oronts\AssetPilotBundle\Merge\DuplicateMergeStrategyInterface:
            tags: ['oronts_asset_pilot.duplicate_merge_strategy']

    Oronts\AssetPilotBundle\Service\DuplicateReferenceRepointer: ~

    Oronts\AssetPilotBundle\Service\DuplicateMergeService:
        arguments:
            $strategies: !tagged_iterator oronts_asset_pilot.duplicate_merge_strategy
            $defaultStrategy: '%oronts_asset_pilot.duplicates.merge_strategy%'
```
Add a parameter mapping for `oronts_asset_pilot.duplicates.merge_strategy` in the extension (mirror how `notifications.enabled` is set).

- [ ] TDD `DuplicateDetectionService::groupForChecksum` (real-sqlite, mirrors the existing ghost-row test). Commit `... -m "Wire duplicate-merge strategy seam + config (merge_strategy)"`.

### Task 7: `POST /duplicates/merge` (Admin)

**Files:**
- Modify: `src/Controller/Api/DuplicatesController.php`

```php
    #[Route('/duplicates/merge', name: 'oronts_asset_pilot_duplicates_merge', methods: ['POST'])]
    #[IsGranted(AssetPilotPermission::Admin->value)]
    public function merge(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent() ?: '[]', true);
        $checksum = is_array($data) && is_string($data['checksum'] ?? null) ? $data['checksum'] : '';
        if ($checksum === '') {
            return new JsonResponse(['error' => 'A checksum is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $canonicalId = is_array($data) && isset($data['canonicalId']) ? (int) $data['canonicalId'] : null;
        $strategy = is_array($data) && is_string($data['strategy'] ?? null) ? $data['strategy'] : null;
        $dryRun = is_array($data) && ($data['dryRun'] ?? false) === true;
        try {
            $outcome = $this->merge->merge($checksum, $canonicalId, $strategy, $dryRun);
            return new JsonResponse([
                'checksum' => $outcome->checksum,
                'canonicalId' => $outcome->canonicalId,
                'dispositions' => array_map(static fn ($d): array => [
                    'copyId' => $d->copyId, 'outcome' => $d->outcome->value, 'reason' => $d->reason,
                ], $outcome->dispositions),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to merge duplicates.', ['exception' => $e]);
            return new JsonResponse(['error' => 'Failed to merge duplicates.'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
```
Inject `DuplicateMergeService $merge` into the controller constructor.

- [ ] TDD a controller-level test if the suite has controller tests for the bundle; otherwise rely on the service tests + a smoke `bin/console` check. Commit `... -m "Add POST /duplicates/merge endpoint (Admin)"`.

### Task 8: `asset-pilot:merge-duplicates` command

**Files:**
- Create: `src/Command/MergeDuplicatesCommand.php`

Options: `--checksum=` (required), `--strategy=` (default from config; validated against `availableStrategies()`), `--canonical=` (int, optional), `--dry-run`. Mirrors the id-targeting + dry-run conventions of the other commands. Prints a per-copy disposition table.

- [ ] TDD the command via `CommandTester` with a mocked `DuplicateMergeService`. Commit `... -m "Add asset-pilot:merge-duplicates command"`.

---

## Cluster 4 — DX docs

### Task 9: Document the merge + the strategy seam

**Files:**
- Modify: the bundle docs (the duplicates feature page + the extension/DX doc that lists tagged seams).

Document: enabling/choosing `duplicates.merge_strategy`; the `DuplicateMergeStrategyInterface` + `oronts_asset_pilot.duplicate_merge_strategy` tag with a copy-paste custom-strategy example; the v1 coverage limits (relations + WYSIWYG; documents/asset-refs/nested/advanced-metadata/hotspot are reported as blocked, never silently merged); the Admin permission + dry-run; and that a partially-referenced copy is never destroyed.

- [ ] Commit `... -m "Document duplicate merge + the merge-strategy extension seam"`.

---

## Self-Review

**Spec coverage:** Strategy seam (Task 2) + extensibility/config (Task 6) ✓; relations + WYSIWYG repoint (Task 4) ✓; disposition strategies quarantine/delete/isolate (Tasks 2,5) ✓; API (Task 7) ✓; command (Task 8) ✓; DX docs (Task 9) ✓; destructive guards: re-verify both assets (Task 4), never dispose a partially-referenced copy (Tasks 2,5), delete re-verifies unreferenced (Task 5), Admin permission (Task 7), LoopGuard on owner saves (Task 4) ✓.

**Type consistency:** `RepointReport` fields/`fullyRepointed`, `CopyDisposition(copyId,outcome,reason)`, `DispositionOutcome` cases, `DuplicateMergeStrategyInterface::{name,disposeCopy}`, `DuplicateMergeService::{merge,availableStrategies,groupForChecksum,pickCanonical}`, `DuplicateReferenceRepointer::repoint` — all referenced consistently across tasks.

**Risk notes:** Task 4 is the only kernel-coupled unit — keep EVERY Pimcore call behind a protected seam (loadAsset, requiredBy, loadObject, relationFields, wysiwygFields, fieldValue, setFieldValue, saveObject, deleteAsset) so tests stay container-free, exactly as VersionRollbackHealer/DuplicateDetectionService already do. Re-run `codex:rescue` after Cluster 2 and after Cluster 3 (destructive surfaces).
