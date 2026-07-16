<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum BulkObjectStatus: string
{
    case Succeeded = 'succeeded';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
