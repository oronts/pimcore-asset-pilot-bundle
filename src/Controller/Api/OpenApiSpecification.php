<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Asset Pilot', description: 'Asset organization, integrity, cleanup, and audit operations.')]
#[OA\Parameter(parameter: 'StudioPrefix', name: 'prefix', in: 'path', required: true, description: 'Studio API route prefix.', schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'Page', name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1))]
#[OA\Parameter(parameter: 'Limit', name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50))]
#[OA\Parameter(parameter: 'Sort', name: 'sort', in: 'query', schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'Order', name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc']))]
#[OA\Parameter(parameter: 'RuleName', name: 'name', in: 'path', required: true, schema: new OA\Schema(type: 'string', minLength: 1))]
#[OA\Parameter(parameter: 'AssetId', name: 'id', in: 'path', required: true, schema: new OA\Schema(ref: '#/components/schemas/PositiveId'))]
#[OA\Parameter(parameter: 'QuarantineAssetId', name: 'assetId', in: 'path', required: true, schema: new OA\Schema(ref: '#/components/schemas/PositiveId'))]
#[OA\Parameter(parameter: 'OperationRunId', name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', pattern: '^[a-f0-9]{32}$'))]
class OpenApiSpecification
{
    private function __construct() {}
}

#[OA\Schema(schema: 'PositiveId', type: 'integer', minimum: 1)]
class OpenApiPositiveIdSchema
{
    private function __construct() {}
}

#[OA\Schema(schema: 'IdList', type: 'array', description: 'A list of ids, each a positive integer or a positive numeric string within the platform integer range. Input handling is lenient: invalid, non-positive, out-of-range, and duplicate members are dropped, then the remaining unique ids are acted on. An empty result after cleaning, or more than 1000 ids after cleaning, is rejected with HTTP 400.', items: new OA\Items(oneOf: [new OA\Schema(type: 'integer', minimum: 1), new OA\Schema(type: 'string', pattern: '^[1-9][0-9]*$')]))]
class OpenApiIdListSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'OperationRunItem',
    type: 'object',
    required: ['key', 'targetType', 'status', 'attempts', 'state', 'createdAt', 'updatedAt'],
    properties: [
        new OA\Property(property: 'key', type: 'string'),
        new OA\Property(property: 'targetType', type: 'string'),
        new OA\Property(property: 'targetId', type: 'integer', nullable: true),
        new OA\Property(property: 'fingerprint', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['queued', 'running', 'completed', 'blocked', 'skipped', 'failed', 'cancelled']),
        new OA\Property(property: 'attempts', type: 'integer', minimum: 0),
        new OA\Property(property: 'state', type: 'object', additionalProperties: true),
        new OA\Property(property: 'result', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'completedAt', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class OpenApiOperationRunItemSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'OperationRunSummary',
    type: 'object',
    required: ['id', 'kind', 'status', 'totalCount', 'processedCount', 'succeededCount', 'skippedCount', 'blockedCount', 'failedCount', 'attempt', 'request', 'createdAt', 'updatedAt'],
    properties: [
        new OA\Property(property: 'id', type: 'string', pattern: '^[a-f0-9]{32}$'),
        new OA\Property(property: 'kind', type: 'string', enum: ['organize', 'reorganize', 'replay', 'duplicate-merge']),
        new OA\Property(property: 'status', type: 'string', enum: ['pending_dispatch', 'queued', 'running', 'cancel_requested', 'cancelled', 'completed', 'blocked', 'partial', 'failed']),
        new OA\Property(property: 'totalCount', type: 'integer', minimum: 0),
        new OA\Property(property: 'processedCount', type: 'integer', minimum: 0),
        new OA\Property(property: 'succeededCount', type: 'integer', minimum: 0),
        new OA\Property(property: 'skippedCount', type: 'integer', minimum: 0),
        new OA\Property(property: 'blockedCount', type: 'integer', minimum: 0),
        new OA\Property(property: 'failedCount', type: 'integer', minimum: 0),
        new OA\Property(property: 'attempt', type: 'integer', minimum: 1),
        new OA\Property(property: 'retryOf', type: 'string', nullable: true),
        new OA\Property(property: 'request', type: 'object', additionalProperties: true),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'startedAt', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'completedAt', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class OpenApiOperationRunSummarySchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'OperationRun',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/OperationRunSummary'),
        new OA\Schema(type: 'object', required: ['items'], properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/OperationRunItem')),
        ]),
    ],
)]
class OpenApiOperationRunSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'ReviewedSelectionResult',
    type: 'object',
    required: ['dryRun', 'planToken', 'runId', 'statusUrl', 'objectCount', 'organized', 'dispatched', 'skipped', 'failed'],
    properties: [
        new OA\Property(property: 'dryRun', type: 'boolean'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Signed, single-use token on preview; null after apply or for an empty selection.'),
        new OA\Property(property: 'runId', type: 'string', pattern: '^[a-f0-9]{32}$', nullable: true),
        new OA\Property(property: 'statusUrl', type: 'string', pattern: '^/', nullable: true),
        new OA\Property(property: 'runStatus', type: 'string', enum: ['pending_dispatch', 'queued', 'running', 'cancel_requested', 'cancelled', 'completed', 'blocked', 'partial', 'failed'], nullable: true),
        new OA\Property(property: 'objectCount', type: 'integer', minimum: 0),
        new OA\Property(property: 'organized', type: 'integer', minimum: 0),
        new OA\Property(property: 'dispatched', type: 'integer', minimum: 0),
        new OA\Property(property: 'skipped', type: 'integer', minimum: 0),
        new OA\Property(property: 'failed', type: 'integer', minimum: 0),
        new OA\Property(property: 'operations', type: 'array', items: new OA\Items(ref: '#/components/schemas/MoveOperationPreview')),
        new OA\Property(property: 'objectResults', type: 'array', items: new OA\Items(type: 'object', required: ['objectId', 'status', 'operationCount'], properties: [
            new OA\Property(property: 'objectId', ref: '#/components/schemas/PositiveId'),
            new OA\Property(property: 'status', type: 'string'),
            new OA\Property(property: 'reason', type: 'string', nullable: true),
            new OA\Property(property: 'operationCount', type: 'integer', minimum: 0),
        ])),
        new OA\Property(property: 'observerWarnings', type: 'array', items: new OA\Items(type: 'string')),
    ],
)]
class OpenApiReviewedSelectionResultSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'Error',
    type: 'object',
    required: ['error'],
    properties: [
        new OA\Property(property: 'error', type: 'string'),
        new OA\Property(property: 'reference', type: 'string', nullable: true),
        new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId', nullable: true),
        new OA\Property(property: 'objectId', ref: '#/components/schemas/PositiveId', nullable: true),
    ],
    additionalProperties: true,
)]
class OpenApiErrorSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'Pagination',
    type: 'object',
    required: ['total', 'page', 'pages'],
    properties: [
        new OA\Property(property: 'total', type: 'integer', minimum: 0, nullable: true, description: 'Null when the actor is workspace-scoped: the authorized total is withheld and hasMore is authoritative.'),
        new OA\Property(property: 'page', type: 'integer', minimum: 1),
        new OA\Property(property: 'pages', type: 'integer', minimum: 0, nullable: true),
        new OA\Property(property: 'hasMore', type: 'boolean'),
        new OA\Property(property: 'truncated', type: 'boolean', description: 'True when a bounded authorization scan stopped before exhausting the result set, so this page (and a CSV export) may be incomplete; absent on unbounded listings.'),
    ],
)]
class OpenApiPaginationSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'AssetItem',
    type: 'object',
    required: ['id', 'path', 'filename', 'full_path', 'type', 'locked', 'size_known'],
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'filename', type: 'string'),
        new OA\Property(property: 'full_path', type: 'string'),
        new OA\Property(property: 'type', type: 'string'),
        new OA\Property(property: 'mimetype', type: 'string', nullable: true),
        new OA\Property(property: 'file_size', type: 'integer', minimum: 0, nullable: true),
        new OA\Property(property: 'size_known', type: 'boolean'),
        new OA\Property(property: 'locked', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'modified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'confidence', type: 'string', enum: ['protected', 'historically_used', 'recently_uploaded', 'probably_unused', 'definitely_unused'], nullable: true),
    ],
)]
class OpenApiAssetItemSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'PaginatedAssets',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Pagination'),
        new OA\Schema(type: 'object', required: ['items'], properties: [new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetItem'))]),
    ],
)]
class OpenApiPaginatedAssetsSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'MoveOperationPreview',
    type: 'object',
    required: ['assetId', 'sourcePath', 'targetPath', 'ruleName'],
    properties: [
        new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'sourcePath', type: 'string'),
        new OA\Property(property: 'targetPath', type: 'string'),
        new OA\Property(property: 'ruleName', type: 'string'),
        new OA\Property(property: 'objectId', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'objectClass', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', nullable: true),
    ],
)]
class OpenApiMoveOperationPreviewSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'OperationResult',
    type: 'object',
    required: ['status', 'message'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_progress', 'recovery_required', 'completed', 'completed_with_observer_error', 'skipped', 'failed']),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'operation', ref: '#/components/schemas/MoveOperationPreview', nullable: true),
    ],
)]
class OpenApiOperationResultSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'AuditEntry',
    type: 'object',
    required: ['id', 'asset_id', 'asset_path_from', 'asset_path_to', 'object_id', 'object_class', 'rule_name', 'trigger_type', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'asset_id', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'asset_path_from', type: 'string'),
        new OA\Property(property: 'asset_path_to', type: 'string'),
        new OA\Property(property: 'object_id', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'object_class', type: 'string'),
        new OA\Property(property: 'rule_name', type: 'string'),
        new OA\Property(property: 'trigger_type', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_progress', 'recovery_required', 'completed', 'completed_with_observer_error', 'skipped', 'failed']),
        new OA\Property(property: 'error_message', type: 'string', nullable: true),
        new OA\Property(property: 'duration_ms', type: 'integer', minimum: 0, nullable: true),
        new OA\Property(property: 'user_id', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'operation_kind', type: 'string', enum: ['move', 'revert'], nullable: true),
        new OA\Property(property: 'actor_type', type: 'string', enum: ['user', 'system', 'anonymous'], nullable: true),
        new OA\Property(property: 'parent_audit_id', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'schema_version', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'committed_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class OpenApiAuditEntrySchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'PaginatedAudit',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Pagination'),
        new OA\Schema(type: 'object', required: ['items'], properties: [new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/AuditEntry'))]),
    ],
)]
class OpenApiPaginatedAuditSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'Rule',
    type: 'object',
    required: ['name', 'class', 'fields', 'targetPath', 'strategy', 'priority', 'enabled', 'filters'],
    properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'class', type: 'string'),
        new OA\Property(property: 'fields', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'condition', type: 'string', nullable: true),
        new OA\Property(property: 'targetPath', type: 'string'),
        new OA\Property(property: 'strategy', type: 'string'),
        new OA\Property(property: 'priority', type: 'integer'),
        new OA\Property(property: 'enabled', type: 'boolean'),
        new OA\Property(property: 'filters', type: 'object', additionalProperties: true),
    ],
)]
class OpenApiRuleSchema
{
    private function __construct() {}
}

#[OA\Schema(schema: 'BulkErrors', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'))]
class OpenApiBulkErrorsSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'IntegrityItem',
    type: 'object',
    required: ['id', 'path', 'checker'],
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'checker', type: 'string'),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ],
)]
class OpenApiIntegrityItemSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'IntegrityHealHistoryItem',
    type: 'object',
    required: ['id', 'assetId', 'path', 'fromVersion', 'checker', 'status', 'createdAt', 'eligible'],
    properties: [
        new OA\Property(property: 'id', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'fromVersion', type: 'integer', minimum: 1),
        new OA\Property(property: 'toVersion', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'checker', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['healed', 'undone']),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'eligible', type: 'boolean'),
        new OA\Property(property: 'eligibilityReason', type: 'string', nullable: true, enum: ['already_undone', 'superseded', 'asset_busy', 'asset_not_found', 'not_permitted', 'asset_locked', 'excluded_folder', 'no_reversible_heal', 'version_missing', 'asset_changed', 'restore_failed', 'log_update_failed']),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ],
)]
class OpenApiIntegrityHealHistoryItemSchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'OperationRecoveryResult',
    type: 'object',
    required: ['operationId', 'assetId', 'kind', 'classification', 'journalUpdated', 'message'],
    properties: [
        new OA\Property(property: 'operationId', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'kind', type: 'string', enum: ['move', 'revert']),
        new OA\Property(property: 'classification', type: 'string', enum: ['completed', 'failed', 'recovery_required']),
        new OA\Property(property: 'journalUpdated', type: 'boolean'),
        new OA\Property(property: 'message', type: 'string'),
    ],
)]
class OpenApiOperationRecoveryResultSchema
{
    private function __construct() {}
}
#[OA\Schema(
    schema: 'DeadOperationDelivery',
    type: 'object',
    required: ['deliveryId', 'operationId', 'deliveryKey', 'observerId', 'outcome', 'attempts', 'lastError', 'updatedAt', 'fingerprint'],
    properties: [
        new OA\Property(property: 'deliveryId', type: 'string', pattern: '^[a-f0-9]{64}$'),
        new OA\Property(property: 'operationId', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'deliveryKey', type: 'string'),
        new OA\Property(property: 'observerId', type: 'string'),
        new OA\Property(property: 'outcome', type: 'string', enum: ['success', 'failure']),
        new OA\Property(property: 'attempts', type: 'integer', minimum: 0),
        new OA\Property(property: 'lastError', type: 'string', nullable: true),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'fingerprint', type: 'string', pattern: '^[a-f0-9]{64}$'),
    ],
)]
class OpenApiDeadOperationDeliverySchema
{
    private function __construct() {}
}

#[OA\Schema(
    schema: 'DeliveryRetryReview',
    type: 'object',
    required: ['applied', 'planToken', 'count', 'deliveries'],
    properties: [
        new OA\Property(property: 'applied', type: 'boolean'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Signed token on preview; null after apply or for an empty scope.'),
        new OA\Property(property: 'count', type: 'integer', minimum: 0),
        new OA\Property(property: 'deliveries', type: 'array', items: new OA\Items(ref: '#/components/schemas/DeadOperationDelivery')),
    ],
)]
class OpenApiDeliveryRetryReviewSchema
{
    private function __construct() {}
}
