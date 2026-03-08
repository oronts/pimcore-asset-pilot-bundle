<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Enum;

enum AssetPilotPermission: string
{
    case View = 'asset_pilot_view';
    case Operate = 'asset_pilot_operate';
    case Admin = 'asset_pilot_admin';

    public const string CATEGORY = 'Asset Pilot';
}
