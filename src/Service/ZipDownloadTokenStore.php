<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;
use Oronts\AssetPilotBundle\Zip\ZipDownloadPlan;
use Psr\Cache\CacheItemPoolInterface;

final class ZipDownloadTokenStore
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly int $ttlSeconds = 300,
    ) {
        if ($this->ttlSeconds <= 0) {
            throw new \InvalidArgumentException('The ZIP download token lifetime must be positive.');
        }
    }

    /** @param list<int> $assetIds */
    public function issue(array $assetIds, ZipBuildOptions $options, ActorContext $actor): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $item = $this->cache->getItem($this->key($token));
        $item->set([
            'actorType' => $actor->type->value,
            'actorUserId' => $actor->userId,
            'assetIds' => $assetIds,
            'strategy' => $options->strategy,
            'thumbnail' => $options->thumbnail,
        ])->expiresAfter($this->ttlSeconds);

        if (!$this->cache->save($item)) {
            throw new \RuntimeException('Could not prepare the ZIP download.');
        }

        return $token;
    }

    public function resolve(string $token, ActorContext $actor): ?ZipDownloadPlan
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return null;
        }

        $item = $this->cache->getItem($this->key($token));
        if (!$item->isHit()) {
            return null;
        }

        $payload = $item->get();
        if (!$this->isValidPayload($payload, $actor)) {
            return null;
        }

        return new ZipDownloadPlan(
            $payload['assetIds'],
            new ZipBuildOptions($payload['strategy'], $payload['thumbnail']),
        );
    }

    private function key(string $token): string
    {
        return 'asset_pilot.zip_download.' . hash('sha256', $token);
    }

    private function isValidPayload(mixed $payload, ActorContext $actor): bool
    {
        if (!is_array($payload)
            || array_keys($payload) !== ['actorType', 'actorUserId', 'assetIds', 'strategy', 'thumbnail']
            || $payload['actorType'] !== $actor->type->value
            || $payload['actorUserId'] !== $actor->userId
            || !is_array($payload['assetIds'])
            || !is_string($payload['strategy']) && $payload['strategy'] !== null
            || !is_string($payload['thumbnail']) && $payload['thumbnail'] !== null
        ) {
            return false;
        }

        return array_is_list($payload['assetIds'])
            && array_all($payload['assetIds'], static fn (mixed $id): bool => is_int($id) && $id > 0);
    }
}
