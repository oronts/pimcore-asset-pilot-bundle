<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Event;

class AssetPilotEvents
{
    public const string PRE_MOVE = 'oronts_asset_pilot.pre_move';
    public const string POST_MOVE = 'oronts_asset_pilot.post_move';
    public const string MOVE_FAILED = 'oronts_asset_pilot.move_failed';
    public const string DURABLE_OPERATION_SUCCEEDED = 'oronts_asset_pilot.operation_succeeded';
    public const string DURABLE_OPERATION_FAILED = 'oronts_asset_pilot.operation_failed';
    public const string BULK_STARTED = 'oronts_asset_pilot.bulk_started';
    public const string BULK_COMPLETED = 'oronts_asset_pilot.bulk_completed';

    public const string ASSET_LOCKED = 'oronts_asset_pilot.asset_locked';
    public const string ASSET_UNLOCKED = 'oronts_asset_pilot.asset_unlocked';
    public const string ASSET_PROPERTY_SET = 'oronts_asset_pilot.asset_property_set';
    public const string ASSETS_TAGGED = 'oronts_asset_pilot.assets_tagged';
    public const string UNUSED_DELETED = 'oronts_asset_pilot.unused_deleted';
    public const string UNUSED_MOVED = 'oronts_asset_pilot.unused_moved';
    public const string REVERTED = 'oronts_asset_pilot.reverted';
    public const string QUARANTINED = 'oronts_asset_pilot.quarantined';
    public const string RESTORED = 'oronts_asset_pilot.restored';
    public const string FILENAME_NORMALIZED = 'oronts_asset_pilot.filename_normalized';
    public const string EMPTY_FOLDER_DELETED = 'oronts_asset_pilot.empty_folder_deleted';
    public const string DUPLICATE_MERGE_COMMITTED = 'oronts_asset_pilot.duplicate_merge_committed';

    public const string INTEGRITY_PRE_HEAL = 'oronts_asset_pilot.integrity_pre_heal';
    public const string INTEGRITY_POST_HEAL = 'oronts_asset_pilot.integrity_post_heal';
}
