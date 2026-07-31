<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

/**
 * Bounded, authorization-filtered selection of a data-object class' view-visible members. The SQL
 * className scope is only a coarse prefilter, so every candidate id is loaded and passed through the
 * native `isAllowed('view')` check while the raw scan stays capped at a candidate budget — a
 * workspace-restricted user can never force an O(entire class) walk. Shared by the bulk-preview
 * (paged) and bulk-organize (whole-selection) entry points so both resolve objects one way.
 *
 * Registered behind this interface with a service alias, so a consumer can decorate or replace it
 * (for example to add a project-specific eligibility filter) without forking the controller.
 */
interface VisibleObjectSelectorInterface
{
    /**
     * One authorized page of a class: fills one visible row past the requested page to derive an honest
     * `hasMore` without computing a total, and reports `truncated` when the candidate budget was hit
     * before the page could be filled.
     *
     * @return array{objects: list<array{id: int, key: string, className: string|null}>, hasMore: bool, truncated: bool}
     */
    public function page(string $className, int $visibleOffset, int $limit): array;

    /**
     * The whole class resolved to its view-visible object ids for a mutation selection: collection
     * stops one past {@see \Oronts\AssetPilotBundle\Support\BulkIds::MAX} and reports `truncated` when
     * the candidate budget was hit first. A mutation must never silently organize a partial class, so
     * the caller rejects a truncated or over-limit result instead of acting on it.
     *
     * @return array{ids: list<int>, truncated: bool}
     */
    public function resolveIds(string $className): array;
}
