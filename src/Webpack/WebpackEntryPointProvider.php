<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Webpack;

use Pimcore\Bundle\StudioUiBundle\Webpack\WebpackEntryPointProviderInterface;

class WebpackEntryPointProvider implements WebpackEntryPointProviderInterface
{
    public function __construct(
        private readonly string $buildRoot = __DIR__ . '/../../public/studio/build',
    ) {}

    public function getEntryPointsJsonLocations(): array
    {
        $activePointer = $this->buildRoot . '/active.json';
        if (!is_file($activePointer)) {
            return [];
        }

        $active = json_decode((string) file_get_contents($activePointer), true, flags: JSON_THROW_ON_ERROR);
        $buildId = $active['buildId'] ?? null;
        if (!is_string($buildId) || !StudioBuildId::isValid($buildId)) {
            throw new \UnexpectedValueException('The active Studio build pointer is invalid.');
        }

        $entrypoints = sprintf('%s/%s/entrypoints.json', $this->buildRoot, $buildId);

        return is_file($entrypoints) ? [$entrypoints] : [];
    }

    public function getEntryPoints(): array
    {
        return [];
    }

    public function getOptionalEntryPoints(): array
    {
        return ['exposeRemote'];
    }
}
