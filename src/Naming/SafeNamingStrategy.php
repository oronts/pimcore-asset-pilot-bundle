<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Naming;

use Oronts\AssetPilotBundle\Enum\CollisionPattern;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Service as AssetService;
use Psr\Log\LoggerInterface;

class SafeNamingStrategy implements NamingStrategyInterface
{
    private const int MAX_ATTEMPTS = 1000;

    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly string $collisionPattern = 'counter',
        protected readonly bool $slugify = true,
    ) {}

    public function generateName(Asset $asset, string $targetPath): string
    {
        $filename = $asset->getFilename();

        if ($this->slugify) {
            $filename = AssetService::getValidKey($filename, 'asset');
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        $fullPath = rtrim($targetPath, '/') . '/' . $filename;

        if (!$this->pathExists($fullPath)) {
            return $filename;
        }

        // If the existing asset at this path IS the same asset, no collision
        $existing = Asset::getByPath($fullPath);
        if ($existing !== null && $existing->getId() === $asset->getId()) {
            return $filename;
        }

        $safeName = match ($this->collisionPattern) {
            CollisionPattern::Counter->value => $this->resolveWithCounter($basename, $extension, $targetPath),
            CollisionPattern::Timestamp->value => $this->resolveWithTimestamp($basename, $extension, $targetPath),
            CollisionPattern::Uuid->value => $this->resolveWithUuid($basename, $extension, $targetPath),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown collision pattern "%s". Supported: %s.',
                $this->collisionPattern,
                implode(', ', CollisionPattern::values()),
            )),
        };

        $this->logger->info('Renamed asset "{original}" to "{safe}" to avoid collision at "{path}".', [
            'original' => $filename,
            'safe' => $safeName,
            'path' => $targetPath,
        ]);

        return $safeName;
    }

    protected function resolveWithCounter(string $basename, string $extension, string $targetPath): string
    {
        $suffix = $extension !== '' ? '.' . $extension : '';

        for ($counter = 1; $counter <= self::MAX_ATTEMPTS; $counter++) {
            $candidate = sprintf('%s_%d%s', $basename, $counter, $suffix);
            if (!$this->pathExists(rtrim($targetPath, '/') . '/' . $candidate)) {
                return $candidate;
            }
        }

        // Too many same-named files to count past; fall back to a collision-resistant uuid name.
        return $this->resolveWithUuid($basename, $extension, $targetPath);
    }

    protected function resolveWithTimestamp(string $basename, string $extension, string $targetPath): string
    {
        $suffix = $extension !== '' ? '.' . $extension : '';

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            // Same-second timestamps collide, so add random entropy after the first attempt.
            $candidate = $attempt === 0
                ? sprintf('%s_%d%s', $basename, time(), $suffix)
                : sprintf('%s_%d_%s%s', $basename, time(), bin2hex(random_bytes(2)), $suffix);
            if (!$this->pathExists(rtrim($targetPath, '/') . '/' . $candidate)) {
                return $candidate;
            }
        }

        return $this->resolveWithUuid($basename, $extension, $targetPath);
    }

    protected function resolveWithUuid(string $basename, string $extension, string $targetPath): string
    {
        $suffix = $extension !== '' ? '.' . $extension : '';
        $candidate = '';

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = sprintf('%s_%s%s', $basename, bin2hex(random_bytes(8)), $suffix);
            if (!$this->pathExists(rtrim($targetPath, '/') . '/' . $candidate)) {
                return $candidate;
            }
        }

        return $candidate;
    }

    protected function pathExists(string $fullPath): bool
    {
        return AssetService::pathExists($fullPath);
    }
}
