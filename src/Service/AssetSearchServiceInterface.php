<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface AssetSearchServiceInterface
{
    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function search(array $filters = [], int $page = 1, int $limit = 50, ?string $sort = null, ?string $order = null): array;

    /** @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int} */
    public function findByObject(int $objectId, int $page = 1, int $limit = 50, ?string $type = null, ?string $sort = null, ?string $order = null): array;

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> asset summaries keyed by id
     */
    public function summarize(array $ids): array;
}
