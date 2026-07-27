<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

interface FailureReplayServiceInterface
{
    /**
     * @param array{since?: string, rule_name?: string, object_class?: string, object_ids?: list<int>} $filters
     * @return list<int>
     */
    public function selectObjects(array $filters = [], ?int $limit = null): array;
}
