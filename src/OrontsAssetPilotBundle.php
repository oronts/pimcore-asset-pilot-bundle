<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle;

use Doctrine\DBAL\Connection;
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
        /** @var Connection $connection */
        $connection = $this->container->get('doctrine.dbal.default_connection');

        return new Installer($this, $connection);
    }
}
