<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

final class DateFilters
{
    public static function validate(array $filters): void
    {
        foreach (['before', 'after'] as $key) {
            if (!empty($filters[$key]) && strtotime((string) $filters[$key]) === false) {
                throw new \InvalidArgumentException(sprintf('Invalid %s date filter.', $key));
            }
        }
    }
}
