<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Pimcore\Model\Asset;

readonly class AssetFieldInfo
{
    /**
     * @param Asset[] $assets
     */
    public function __construct(
        public string $fieldName,
        public ?string $locale,
        public string $fieldType,
        public array $assets,
    ) {}
}
