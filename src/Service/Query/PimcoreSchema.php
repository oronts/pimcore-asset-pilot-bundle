<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

/**
 * Single source of truth for the Pimcore core table names and element-type discriminators this
 * bundle queries directly. Keeps the raw SQL in the services from drifting on these magic strings.
 */
class PimcoreSchema
{
    public const string TABLE_ASSETS = 'assets';
    public const string TABLE_OBJECTS = 'objects';
    public const string TABLE_DOCUMENTS = 'documents';
    public const string TABLE_DEPENDENCIES = 'dependencies';
    public const string TABLE_PROPERTIES = 'properties';

    public const string ELEMENT_TYPE_ASSET = 'asset';
    public const string ELEMENT_TYPE_OBJECT = 'object';
    public const string ELEMENT_TYPE_DOCUMENT = 'document';

    public const string ASSET_TYPE_FOLDER = 'folder';

    private function __construct() {}
}
