<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Event;

class AssetPilotEvents
{
    public const string PRE_MOVE = 'oronts_asset_pilot.pre_move';
    public const string POST_MOVE = 'oronts_asset_pilot.post_move';
    public const string MOVE_FAILED = 'oronts_asset_pilot.move_failed';
    public const string BULK_STARTED = 'oronts_asset_pilot.bulk_started';
    public const string BULK_COMPLETED = 'oronts_asset_pilot.bulk_completed';
}
