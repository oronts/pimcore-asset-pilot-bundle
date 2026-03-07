<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum TriggerType: string
{
    case ObjectSave = 'object_save';
    case BulkOperation = 'bulk_operation';
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Api = 'api';
    case AssetUpload = 'asset_upload';
}
