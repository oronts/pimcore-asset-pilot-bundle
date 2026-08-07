<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Support;

class UniqueServiceMap
{
    /**
     * @template T of object
     * @param iterable<T> $services
     * @param callable(T): string $alias
     * @return array<string, T>
     */
    public static function from(iterable $services, callable $alias, string $kind): array
    {
        $map = [];
        foreach ($services as $service) {
            $name = trim($alias($service));
            if ($name === '') {
                throw new \LogicException(sprintf('%s aliases must not be empty.', $kind));
            }
            if (isset($map[$name])) {
                throw new \LogicException(sprintf('Duplicate %s alias "%s".', $kind, $name));
            }
            $map[$name] = $service;
        }

        return $map;
    }
}
