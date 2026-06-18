<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health\Check;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Psr\Cache\CacheItemPoolInterface;

/**
 * LoopGuard idempotency (Decision #6 / P2-8) only holds when cache.app is shared across workers and
 * pods. This is a best-effort heuristic on the adapter class: a per-process adapter is flagged as a
 * Warning; anything else passes with the caveat that the backend could not be proven shared.
 */
class SharedCacheHealthCheck implements HealthCheckInterface
{
    /** Substrings of Symfony cache adapters that are not guaranteed shared across workers/pods. */
    private const array NON_SHARED_MARKERS = ['Array', 'Filesystem', 'PhpFiles', 'PhpArray', 'Apcu', 'Null'];

    public function __construct(
        protected readonly CacheItemPoolInterface $cache,
    ) {}

    public function name(): string
    {
        return 'shared_cache';
    }

    public function run(): HealthCheckResult
    {
        $class = $this->cache::class;
        $shortName = ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;

        foreach (self::NON_SHARED_MARKERS as $marker) {
            if (str_contains($shortName, $marker)) {
                return new HealthCheckResult(
                    $this->name(),
                    HealthStatus::Warning,
                    sprintf('cache.app (%s) is not guaranteed shared across workers/pods, so LoopGuard idempotency may not hold there. Use a shared store (Redis) in production.', $shortName),
                    ['adapter' => $class],
                );
            }
        }

        return new HealthCheckResult(
            $this->name(),
            HealthStatus::Ok,
            sprintf('cache.app (%s) is not a known per-process store; ensure it is shared (Redis) across workers.', $shortName),
            ['adapter' => $class],
        );
    }
}
