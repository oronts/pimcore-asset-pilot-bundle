<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Naming;

use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Service as AssetService;
use Psr\Log\LoggerInterface;

class SafeNamingStrategy implements NamingStrategyInterface
{
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

        if (!AssetService::pathExists($fullPath)) {
            return $filename;
        }

        // If the existing asset at this path IS the same asset, no collision
        $existing = Asset::getByPath($fullPath);
        if ($existing !== null && $existing->getId() === $asset->getId()) {
            return $filename;
        }

        $safeName = match ($this->collisionPattern) {
            'counter' => $this->resolveWithCounter($basename, $extension, $targetPath),
            'timestamp' => $this->resolveWithTimestamp($basename, $extension),
            'uuid' => $this->resolveWithUuid($basename, $extension),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown collision pattern "%s". Supported: counter, timestamp, uuid.',
                $this->collisionPattern,
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
        $counter = 1;
        $suffix = $extension !== '' ? '.' . $extension : '';

        do {
            $candidate = sprintf('%s_%d%s', $basename, $counter, $suffix);
            $fullPath = rtrim($targetPath, '/') . '/' . $candidate;
            $counter++;
        } while (AssetService::pathExists($fullPath));

        return $candidate;
    }

    protected function resolveWithTimestamp(string $basename, string $extension): string
    {
        $suffix = $extension !== '' ? '.' . $extension : '';

        return sprintf('%s_%d%s', $basename, time(), $suffix);
    }

    protected function resolveWithUuid(string $basename, string $extension): string
    {
        $suffix = $extension !== '' ? '.' . $extension : '';
        $shortUuid = substr(bin2hex(random_bytes(4)), 0, 8);

        return sprintf('%s_%s%s', $basename, $shortUuid, $suffix);
    }
}
