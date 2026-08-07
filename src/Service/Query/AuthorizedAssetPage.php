<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Pimcore\Model\Asset;

/**
 * Shared authorized pager for the SQL-backed asset listings. The SQL workspace scope is only a coarse
 * prefilter; native `AbstractElement::isAllowed('view')` also evaluates workflow and permission-event
 * denials, so every emitted row is passed through it before disclosure.
 *
 * Only the supervised System actor bypasses the native check and keeps the exact SQL total. Every other
 * actor (including a Pimcore admin, who is still subject to workflow denial) is served by a bounded
 * scan-and-fill: raw candidate rows are walked in chunks, natively authorized, and accumulated until the
 * page is full plus one extra authorized row. This guarantees a full page even when denied rows sit
 * between authorized ones, derives `hasMore` only from a genuine authorized surplus (never the mere
 * existence of a raw row), and withholds the SQL total (it would let a scoped actor count or binary-search
 * assets the native check hides). The scan is bounded by {@see $maxCandidates}; a page that cannot be
 * resolved within that budget reports `truncated: true` rather than a misleading `hasMore: false` end,
 * so a caller can surface "results limited" instead of silently claiming the listing ended.
 */
class AuthorizedAssetPage
{
    /** @var callable(int): ?Asset */
    private readonly mixed $assetLoader;

    /**
     * @param (callable(int): ?Asset)|null $assetLoader
     * @param int                          $maxCandidates hard ceiling on raw rows scanned per page for a native-checked actor
     * @param int                          $batchSize     raw rows fetched per window call during a scan
     * @param int                          $exportMaxRows default row ceiling for {@see iterateAuthorized} CSV exports
     */
    public function __construct(
        private readonly ElementAuthorizationInterface $authorization,
        private readonly AssetWorkspaceQueryScope $scope,
        ?callable $assetLoader = null,
        private readonly int $maxCandidates = 5000,
        private readonly int $batchSize = 100,
        private readonly int $exportMaxRows = 200_000,
    ) {
        $this->assetLoader = $assetLoader ?? static fn (int $id): ?Asset => Asset::getById($id);
    }

    /**
     * @param callable(): int                                            $exactTotal exact SQL total, used only for the System bypass
     * @param callable(int, int): list<array<string, mixed>>             $window     fetch raw rows for (offset, limit)
     * @param callable(array<string, mixed>): ?int                       $assetIdOf  the asset id a row must be authorized against
     *
     * @return array{items: list<array<string, mixed>>, total: ?int, hasMore: bool, truncated: bool}
     */
    public function paginate(int $page, int $limit, callable $exactTotal, callable $window, callable $assetIdOf): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);

        if ($this->scope->bypassesNativeAuthorization()) {
            $offset = ($page - 1) * $limit;
            $total = $exactTotal();
            $items = array_values($window($offset, $limit));

            return ['items' => $items, 'total' => $total, 'hasMore' => ($offset + count($items)) < $total, 'truncated' => false];
        }

        return $this->scanAndFill($page, $limit, $window, $assetIdOf);
    }

    /**
     * @param callable(int, int): list<array<string, mixed>> $window
     * @param callable(array<string, mixed>): ?int           $assetIdOf
     *
     * @return array{items: list<array<string, mixed>>, total: null, hasMore: bool, truncated: bool}
     */
    private function scanAndFill(int $page, int $limit, callable $window, callable $assetIdOf): array
    {
        $needed = $page * $limit + 1;
        $ceiling = $this->maxCandidates;
        $batch = max(1, $this->batchSize);

        $authorized = [];
        $scanned = 0;
        $rawOffset = 0;
        $exhausted = false;

        while (count($authorized) < $needed && $scanned < $ceiling) {
            $rows = array_values($window($rawOffset, $batch));
            if ($rows === []) {
                $exhausted = true;
                break;
            }
            foreach ($rows as $row) {
                ++$scanned;
                $assetId = $assetIdOf($row);
                if ($assetId !== null) {
                    $asset = ($this->assetLoader)($assetId);
                    if ($asset !== null && $this->authorization->isAllowed($asset, 'view')) {
                        $authorized[] = $row;
                        if (count($authorized) >= $needed) {
                            break;
                        }
                    }
                }
                if ($scanned >= $ceiling) {
                    break;
                }
            }
            $rawOffset += count($rows);
            if (count($rows) < $batch) {
                $exhausted = true;
                break;
            }
        }

        $hitBudget = !$exhausted && count($authorized) < $needed;

        return [
            'items' => array_values(array_slice($authorized, ($page - 1) * $limit, $limit)),
            'total' => null,
            'hasMore' => count($authorized) > $page * $limit,
            'truncated' => $hitBudget && $this->hasFurtherAuthorized($window, $assetIdOf, false, $scanned, $this->batchSize),
        ];
    }

    /**
     * Probe whether a natively-authorized row exists past $offset, so a fully-denied tail is not misreported
     * as truncated. Bounded to one window to keep the cost flat: an authorized row in the window is a real
     * remainder; an empty/short window is a genuine end; a full all-denied window reports "more" conservatively
     * (never silently claims complete) because further authorized rows may lie beyond it.
     *
     * @param callable(int, int): list<array<string, mixed>> $window
     * @param callable(array<string, mixed>): ?int           $assetIdOf
     */
    private function hasFurtherAuthorized(callable $window, callable $assetIdOf, bool $bypass, int $offset, int $batch): bool
    {
        $batch = max(1, $batch);
        $rows = array_values($window($offset, $batch));
        if ($rows === []) {
            return false;
        }
        if ($bypass) {
            return true;
        }
        foreach ($rows as $row) {
            $assetId = $assetIdOf($row);
            if ($assetId === null) {
                continue;
            }
            $asset = ($this->assetLoader)($assetId);
            if ($asset !== null && $this->authorization->isAllowed($asset, 'view')) {
                return true;
            }
        }

        return count($rows) === $batch;
    }

    /**
     * Stream every natively-authorized row (for CSV export), bounded only by {@see $maxRows}. The generator
     * return value is `true` when that ceiling cut the stream short and `false` on genuine exhaustion, so a
     * caller can read `->getReturn()` after draining and mark the export as truncated instead of complete.
     * The $window ordering must be stable or offset paging can drop or duplicate rows.
     *
     * @param callable(int, int): list<array<string, mixed>> $window
     * @param callable(array<string, mixed>): ?int           $assetIdOf
     *
     * @return \Generator<int, array<string, mixed>, mixed, bool>
     */
    public function iterateAuthorized(callable $window, callable $assetIdOf, int $batch = 500, ?int $maxRows = null): \Generator
    {
        $maxRows ??= $this->exportMaxRows;
        $bypass = $this->scope->bypassesNativeAuthorization();
        $batch = max(1, $batch);
        $offset = 0;
        $emitted = 0;

        while ($emitted < $maxRows) {
            $rows = array_values($window($offset, $batch));
            if ($rows === []) {
                return false;
            }
            foreach ($rows as $i => $row) {
                if (!$bypass) {
                    $assetId = $assetIdOf($row);
                    if ($assetId === null) {
                        continue;
                    }
                    $asset = ($this->assetLoader)($assetId);
                    if ($asset === null || !$this->authorization->isAllowed($asset, 'view')) {
                        continue;
                    }
                }
                yield $row;
                if (++$emitted >= $maxRows) {
                    return $this->hasFurtherAuthorized($window, $assetIdOf, $bypass, $offset + $i + 1, $batch);
                }
            }
            $offset += count($rows);
            if (count($rows) < $batch) {
                return false;
            }
        }

        return false;
    }
}
