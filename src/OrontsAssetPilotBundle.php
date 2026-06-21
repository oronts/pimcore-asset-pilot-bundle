<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;

class OrontsAssetPilotBundle extends AbstractPimcoreBundle
{
    use PackageVersionTrait;

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    protected function getComposerPackageName(): string
    {
        return 'oronts/asset-pilot-bundle';
    }

    public function getInstaller(): Installer
    {
        /** @var Installer $installer */
        $installer = $this->container->get(Installer::class);

        return $installer;
    }
}
