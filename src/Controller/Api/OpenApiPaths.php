<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api;

use OpenApi\Attributes as OA;

/**
 * Filename-matching anchor so PSR-4 can autoload this multi-class OpenAPI paths file (reflected first, it
 * declares every path class below). The OA attributes carry no runtime logic; split from OpenApiSpecification
 * to keep each file reviewable.
 */
class OpenApiPaths
{
    private function __construct() {}
}

#[OA\Get(
    path: '{prefix}/asset-pilot/health',
    operationId: 'asset_pilot_health',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    responses: [
        new OA\Response(response: 200, description: 'Health report', content: new OA\JsonContent(type: 'object', required: ['status', 'checks'], properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['ok', 'warning', 'critical']),
            new OA\Property(property: 'checks', type: 'array', items: new OA\Items(type: 'object', required: ['name', 'status', 'message', 'details'], properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'status', type: 'string', enum: ['ok', 'warning', 'critical']),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'details', type: 'object', additionalProperties: true),
            ])),
        ])),
        new OA\Response(response: 500, description: 'Health check failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/health/readiness',
    operationId: 'asset_pilot_health_readiness',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    responses: [
        new OA\Response(response: 200, description: 'Ready or degraded', content: new OA\JsonContent(type: 'object', required: ['status'], properties: [new OA\Property(property: 'status', type: 'string', enum: ['ok', 'warning'])])),
        new OA\Response(response: 503, description: 'Critical dependency failure', content: new OA\JsonContent(type: 'object', required: ['status'], properties: [new OA\Property(property: 'status', type: 'string', enum: ['critical'])])),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/unused-assets',
    operationId: 'asset_pilot_unused_assets',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/Page'),
        new OA\Parameter(ref: '#/components/parameters/Limit'),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'extension', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'before', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'after', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'folder', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'confidence', in: 'query', schema: new OA\Schema(type: 'string', enum: ['protected', 'historically_used', 'recently_uploaded', 'probably_unused', 'definitely_unused'])),
        new OA\Parameter(ref: '#/components/parameters/Sort'),
        new OA\Parameter(ref: '#/components/parameters/Order'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Unused assets', content: new OA\JsonContent(ref: '#/components/schemas/PaginatedAssets')),
        new OA\Response(response: 400, description: 'Invalid or unsupported filters', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/unused-assets/stats',
    operationId: 'asset_pilot_unused_assets_stats',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    responses: [
        new OA\Response(response: 200, description: 'Unused asset statistics', content: new OA\JsonContent(type: 'object', required: ['totalCount', 'totalSize', 'totalSizeFormatted', 'unknownSizeCount', 'byType'], properties: [
            new OA\Property(property: 'totalCount', type: 'integer', minimum: 0),
            new OA\Property(property: 'totalSize', type: 'integer', minimum: 0),
            new OA\Property(property: 'totalSizeFormatted', type: 'string'),
            new OA\Property(property: 'unknownSizeCount', type: 'integer', minimum: 0),
            new OA\Property(property: 'byType', type: 'array', items: new OA\Items(type: 'object', required: ['type', 'count', 'total_size', 'unknown_size_count'], properties: [
                new OA\Property(property: 'type', type: 'string'),
                new OA\Property(property: 'count', type: 'integer', minimum: 0),
                new OA\Property(property: 'total_size', type: 'integer', minimum: 0),
                new OA\Property(property: 'unknown_size_count', type: 'integer', minimum: 0),
            ])),
        ])),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/unused-assets/export',
    operationId: 'asset_pilot_unused_assets_export',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'extension', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'before', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'after', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'folder', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'confidence', in: 'query', schema: new OA\Schema(type: 'string', enum: ['protected', 'historically_used', 'recently_uploaded', 'probably_unused', 'definitely_unused'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Unused asset CSV export', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
        new OA\Response(response: 400, description: 'Invalid or unsupported filters', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/unused-assets/bulk-delete',
    operationId: 'asset_pilot_unused_assets_bulk_delete',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['assetIds'], properties: [
        new OA\Property(property: 'assetIds', ref: '#/components/schemas/IdList'),
        new OA\Property(property: 'dryRun', type: 'boolean', default: false, description: 'Set true to preview and receive a signed planToken.'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required on apply; returned by a matching dry-run preview.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Delete preview or result', content: new OA\JsonContent(type: 'object', required: ['deleted', 'failed', 'errors', 'observerWarnings', 'dryRun', 'planToken', 'eligible'], properties: [
            new OA\Property(property: 'deleted', type: 'integer', minimum: 0),
            new OA\Property(property: 'failed', type: 'integer', minimum: 0),
            new OA\Property(property: 'errors', ref: '#/components/schemas/BulkErrors'),
            new OA\Property(property: 'observerWarnings', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'dryRun', type: 'boolean'),
            new OA\Property(property: 'planToken', type: 'string', nullable: true),
            new OA\Property(property: 'eligible', type: 'integer', minimum: 0),
        ])),
        new OA\Response(response: 400, description: 'Invalid asset IDs, dryRun, or plan token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Operate permission required'),
        new OA\Response(response: 409, description: 'Plan is stale or already used', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'The bulk operation failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/unused-assets/bulk-move',
    operationId: 'asset_pilot_unused_assets_bulk_move',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['assetIds', 'targetFolder'], properties: [
        new OA\Property(property: 'assetIds', ref: '#/components/schemas/IdList'),
        new OA\Property(property: 'targetFolder', type: 'string', minLength: 1),
        new OA\Property(property: 'dryRun', type: 'boolean', default: false, description: 'Set true to preview and receive a signed planToken.'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required on apply; returned by a matching dry-run preview.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Move preview or result', content: new OA\JsonContent(type: 'object', required: ['moved', 'failed', 'errors', 'observerWarnings', 'dryRun', 'planToken', 'eligible'], properties: [
            new OA\Property(property: 'moved', type: 'integer', minimum: 0),
            new OA\Property(property: 'failed', type: 'integer', minimum: 0),
            new OA\Property(property: 'errors', ref: '#/components/schemas/BulkErrors'),
            new OA\Property(property: 'observerWarnings', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'dryRun', type: 'boolean'),
            new OA\Property(property: 'planToken', type: 'string', nullable: true),
            new OA\Property(property: 'eligible', type: 'integer', minimum: 0),
        ])),
        new OA\Response(response: 400, description: 'Invalid asset IDs, target folder, dryRun, or plan token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Operate permission required'),
        new OA\Response(response: 409, description: 'Plan is stale or already used', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'The bulk operation failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/unused-assets/bulk-quarantine',
    operationId: 'asset_pilot_unused_assets_bulk_quarantine',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['assetIds'], properties: [
        new OA\Property(property: 'assetIds', ref: '#/components/schemas/IdList'),
        new OA\Property(property: 'dryRun', type: 'boolean', default: false, description: 'Set true to preview and receive a signed planToken.'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required on apply; returned by a matching dry-run preview.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Quarantine preview or result', content: new OA\JsonContent(type: 'object', required: ['quarantined', 'failed', 'errors', 'observerWarnings', 'dryRun', 'planToken', 'eligible'], properties: [
            new OA\Property(property: 'quarantined', type: 'integer', minimum: 0),
            new OA\Property(property: 'failed', type: 'integer', minimum: 0),
            new OA\Property(property: 'errors', ref: '#/components/schemas/BulkErrors'),
            new OA\Property(property: 'observerWarnings', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'dryRun', type: 'boolean'),
            new OA\Property(property: 'planToken', type: 'string', nullable: true),
            new OA\Property(property: 'eligible', type: 'integer', minimum: 0),
        ])),
        new OA\Response(response: 400, description: 'Invalid asset IDs, dryRun, or plan token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Operate permission required'),
        new OA\Response(response: 409, description: 'Plan is stale or already used', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'The bulk operation failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
class OpenApiHealthAndUnusedSpecification
{
    private function __construct() {}
}

#[OA\Get(
    path: '{prefix}/asset-pilot/rules/drift',
    operationId: 'asset_pilot_rules_drift',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(name: 'class', in: 'query', required: true, schema: new OA\Schema(type: 'string', minLength: 1)),
        new OA\Parameter(ref: '#/components/parameters/Page'),
        new OA\Parameter(ref: '#/components/parameters/Limit'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Location drift', content: new OA\JsonContent(type: 'object', required: ['items', 'objectsScanned', 'page', 'limit', 'truncated'], properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', required: ['assetId', 'currentPath', 'expectedPath', 'ruleName', 'eligibility'], properties: [
                new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId'),
                new OA\Property(property: 'currentPath', type: 'string'),
                new OA\Property(property: 'expectedPath', type: 'string'),
                new OA\Property(property: 'ruleName', type: 'string'),
                new OA\Property(property: 'eligibility', type: 'string'),
                new OA\Property(property: 'reason', type: 'string', nullable: true),
            ])),
            new OA\Property(property: 'objectsScanned', type: 'integer', minimum: 0),
            new OA\Property(property: 'page', type: 'integer', minimum: 1),
            new OA\Property(property: 'limit', type: 'integer', minimum: 1),
            new OA\Property(property: 'truncated', type: 'boolean', description: 'True when the authorized scan hit its candidate budget before filling the page.'),
        ])),
        new OA\Response(response: 400, description: 'Missing class', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Drift analysis failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/rules/overlap',
    operationId: 'asset_pilot_rules_overlap',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    responses: [
        new OA\Response(response: 200, description: 'Potential rule overlaps', content: new OA\JsonContent(type: 'object', required: ['overlaps'], properties: [
            new OA\Property(property: 'overlaps', type: 'array', items: new OA\Items(type: 'object', required: ['ruleA', 'ruleB', 'class', 'sharedFields', 'samePriority'], properties: [
                new OA\Property(property: 'ruleA', type: 'string'),
                new OA\Property(property: 'ruleB', type: 'string'),
                new OA\Property(property: 'class', type: 'string'),
                new OA\Property(property: 'sharedFields', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'higherPriority', type: 'string', nullable: true),
                new OA\Property(property: 'samePriority', type: 'boolean'),
            ])),
        ])),
        new OA\Response(response: 500, description: 'Overlap analysis failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/rules/export',
    operationId: 'asset_pilot_rules_export',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    responses: [
        new OA\Response(response: 200, description: 'Rule export', content: new OA\JsonContent(type: 'object', required: ['format_version', 'rules'], properties: [
            new OA\Property(property: 'format_version', type: 'integer', enum: [1]),
            new OA\Property(property: 'rules', type: 'object', additionalProperties: true),
        ])),
        new OA\Response(response: 500, description: 'Rule export failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/rules/diff',
    operationId: 'asset_pilot_rules_diff',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['rules'], properties: [
        new OA\Property(property: 'format_version', type: 'integer'),
        new OA\Property(property: 'rules', type: 'object', additionalProperties: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Rule diff', content: new OA\JsonContent(type: 'object', required: ['added', 'removed', 'changed', 'unchanged', 'hasChanges'], properties: [
            new OA\Property(property: 'added', type: 'object', additionalProperties: true),
            new OA\Property(property: 'removed', type: 'object', additionalProperties: true),
            new OA\Property(property: 'changed', type: 'object', additionalProperties: true),
            new OA\Property(property: 'unchanged', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'hasChanges', type: 'boolean'),
        ])),
        new OA\Response(response: 400, description: 'Invalid JSON object', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Rule diff failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/rules',
    operationId: 'asset_pilot_rules',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    responses: [
        new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Rule'))),
        new OA\Response(response: 500, description: 'Rule listing failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/rules/{name}',
    operationId: 'asset_pilot_rule_detail',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/RuleName'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Rule detail', content: new OA\JsonContent(allOf: [
            new OA\Schema(ref: '#/components/schemas/Rule'),
            new OA\Schema(type: 'object', required: ['stats'], properties: [new OA\Property(property: 'stats', type: 'object', additionalProperties: true)]),
        ])),
        new OA\Response(response: 404, description: 'Rule not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Rule lookup failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/rules/{name}/preview',
    operationId: 'asset_pilot_rules_preview',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/RuleName'),
        new OA\Parameter(name: 'objectId', in: 'query', required: true, schema: new OA\Schema(ref: '#/components/schemas/PositiveId')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Rule preview', content: new OA\JsonContent(type: 'object', required: ['operations', 'planToken'], properties: [
            new OA\Property(property: 'operations', type: 'array', items: new OA\Items(ref: '#/components/schemas/MoveOperationPreview')),
            new OA\Property(property: 'planToken', type: 'string', minLength: 1),
        ])),
        new OA\Response(response: 400, description: 'Invalid object ID', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Object is not viewable', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'Rule or object not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Rule preview failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/rules/{name}/apply',
    operationId: 'asset_pilot_rules_apply',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/RuleName'),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['objectId', 'planToken'], properties: [
        new OA\Property(property: 'objectId', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'planToken', type: 'string', minLength: 1),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Rule application result', content: new OA\JsonContent(type: 'object', required: ['rule', 'results'], properties: [
            new OA\Property(property: 'rule', type: 'string'),
            new OA\Property(property: 'results', type: 'array', items: new OA\Items(ref: '#/components/schemas/OperationResult')),
        ])),
        new OA\Response(response: 400, description: 'Invalid object ID', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Object cannot be published', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'Rule or object not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'Preview plan is stale or does not match', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
class OpenApiRulesSpecification
{
    private function __construct() {}
}

#[OA\Get(
    path: '{prefix}/asset-pilot/duplicates',
    operationId: 'asset_pilot_duplicates',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/Page'),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50)),
        new OA\Parameter(name: 'minCopies', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 2, default: 2)),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Duplicate groups', content: new OA\JsonContent(type: 'object', required: ['items', 'total', 'page', 'limit', 'hasMore', 'truncated'], properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', required: ['checksum', 'fileSize', 'count', 'assetIds', 'representative'], properties: [
                new OA\Property(property: 'checksum', type: 'string'),
                new OA\Property(property: 'fileSize', type: 'integer', minimum: 0),
                new OA\Property(property: 'count', type: 'integer', minimum: 2),
                new OA\Property(property: 'assetIds', ref: '#/components/schemas/IdList'),
                new OA\Property(property: 'representative', type: 'object', nullable: true, required: ['id', 'filename', 'fullPath', 'fileSize', 'type'], properties: [
                    new OA\Property(property: 'id', ref: '#/components/schemas/PositiveId'),
                    new OA\Property(property: 'filename', type: 'string'),
                    new OA\Property(property: 'fullPath', type: 'string'),
                    new OA\Property(property: 'fileSize', type: 'integer', minimum: 0),
                    new OA\Property(property: 'type', type: 'string'),
                ]),
            ])),
            new OA\Property(property: 'total', type: 'integer', minimum: 0, nullable: true, description: 'Null when the actor is workspace-scoped; the total is not computed for a scoped listing.'),
            new OA\Property(property: 'page', type: 'integer', minimum: 1),
            new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 100),
            new OA\Property(property: 'hasMore', type: 'boolean'),
            new OA\Property(property: 'truncated', type: 'boolean'),
        ])),
        new OA\Response(response: 500, description: 'Duplicate listing failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/duplicates/export',
    operationId: 'asset_pilot_duplicates_export',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(name: 'minCopies', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 2, default: 2)),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Duplicate CSV export', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/duplicates/strategies',
    operationId: 'asset_pilot_duplicates_strategies',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    responses: [
        new OA\Response(response: 200, description: 'Merge strategies', content: new OA\JsonContent(type: 'object', required: ['strategies', 'default'], properties: [
            new OA\Property(property: 'strategies', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'default', type: 'string'),
        ])),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/duplicates/merge',
    operationId: 'asset_pilot_duplicates_merge',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(oneOf: [
        new OA\Schema(type: 'object', required: ['checksum'], properties: [
            new OA\Property(property: 'checksum', type: 'string', minLength: 1),
            new OA\Property(property: 'canonicalId', ref: '#/components/schemas/PositiveId', nullable: true),
            new OA\Property(property: 'strategy', type: 'string', nullable: true),
            new OA\Property(property: 'dryRun', type: 'boolean', default: false),
            new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required on apply; returned by a matching dry-run preview.'),
        ]),
        new OA\Schema(type: 'object', required: ['runId'], properties: [
            new OA\Property(property: 'runId', type: 'string', pattern: '^[a-f0-9]{32}$', description: 'Actor-scoped persisted merge run to resume.'),
        ]),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Merge preview, apply, or resume result', content: new OA\JsonContent(type: 'object', required: ['checksum', 'canonicalId', 'dryRun', 'planToken', 'runId', 'status', 'statusUrl', 'dispositions'], properties: [
            new OA\Property(property: 'checksum', type: 'string'),
            new OA\Property(property: 'canonicalId', ref: '#/components/schemas/PositiveId'),
            new OA\Property(property: 'dryRun', type: 'boolean'),
            new OA\Property(property: 'planToken', type: 'string', nullable: true),
            new OA\Property(property: 'runId', type: 'string', pattern: '^[a-f0-9]{32}$', nullable: true),
            new OA\Property(property: 'status', type: 'string', enum: ['pending_dispatch', 'queued', 'running', 'cancel_requested', 'cancelled', 'completed', 'blocked', 'partial', 'failed'], nullable: true),
            new OA\Property(property: 'statusUrl', type: 'string', pattern: '^/', nullable: true),
            new OA\Property(property: 'dispositions', type: 'array', items: new OA\Items(type: 'object', required: ['copyId', 'outcome'], properties: [
                new OA\Property(property: 'copyId', ref: '#/components/schemas/PositiveId'),
                new OA\Property(property: 'outcome', type: 'string', enum: ['quarantined', 'deleted', 'left_referenced', 'left_error', 'blocked', 'skipped']),
                new OA\Property(property: 'reason', type: 'string'),
            ])),
        ])),
        new OA\Response(response: 400, description: 'Invalid checksum, strategy, or JSON', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Merge is not permitted', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'Duplicate group not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'Merge conflict: the preview plan is stale or already consumed, or a merge ownership/lease or finalization conflict occurred. On a finalization conflict the body also carries the resumable runId and its statusUrl; POST that runId back to complete the run.', content: new OA\JsonContent(type: 'object', required: ['error'], additionalProperties: true, properties: [
            new OA\Property(property: 'error', type: 'string'),
            new OA\Property(property: 'runId', type: 'string', pattern: '^[a-f0-9]{32}$', nullable: true, description: 'Present only for a finalization conflict; POST this runId back to /duplicates/merge to complete the run.'),
            new OA\Property(property: 'rootRunId', type: 'string', pattern: '^[a-f0-9]{32}$', nullable: true),
            new OA\Property(property: 'statusUrl', type: 'string', nullable: true),
        ])),
        new OA\Response(response: 500, description: 'Merge failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
class OpenApiDuplicateSpecification
{
    private function __construct() {}
}

#[OA\Post(
    path: '{prefix}/asset-pilot/assets/download-zip',
    operationId: 'asset_pilot_assets_download_zip',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['assetIds'], properties: [
        new OA\Property(property: 'assetIds', ref: '#/components/schemas/IdList'),
        new OA\Property(property: 'strategy', type: 'string', nullable: true),
        new OA\Property(property: 'thumbnail', type: 'string', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'ZIP archive', headers: [
            new OA\Header(header: 'X-Asset-Pilot-Requested', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Header(header: 'X-Asset-Pilot-Added', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Header(header: 'X-Asset-Pilot-Skipped', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Header(header: 'X-Asset-Pilot-Truncated', schema: new OA\Schema(type: 'boolean')),
        ], content: new OA\MediaType(mediaType: 'application/zip', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(response: 400, description: 'Invalid asset IDs or JSON', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 422, description: 'Archive cannot be built', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'ZIP creation failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/assets/download-zip/prepare',
    operationId: 'asset_pilot_assets_download_zip_prepare',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['assetIds'], properties: [
        new OA\Property(property: 'assetIds', ref: '#/components/schemas/IdList'),
        new OA\Property(property: 'strategy', type: 'string', nullable: true),
        new OA\Property(property: 'thumbnail', type: 'string', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 201, description: 'Prepared user-bound download token', content: new OA\JsonContent(type: 'object', required: ['token'], properties: [
            new OA\Property(property: 'token', type: 'string', pattern: '^[A-Za-z0-9_-]{43}$'),
        ])),
        new OA\Response(response: 400, description: 'Invalid asset IDs, options, or JSON', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Download preparation failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/assets/download-zip/{token}',
    operationId: 'asset_pilot_assets_download_zip_stream',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string', pattern: '^[A-Za-z0-9_-]{43}$')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'ZIP archive', headers: [
            new OA\Header(header: 'X-Asset-Pilot-Requested', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Header(header: 'X-Asset-Pilot-Added', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Header(header: 'X-Asset-Pilot-Skipped', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Header(header: 'X-Asset-Pilot-Truncated', schema: new OA\Schema(type: 'boolean')),
        ], content: new OA\MediaType(mediaType: 'application/zip', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(response: 404, description: 'Download token not found, expired, or owned by another actor', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 422, description: 'Archive cannot be built', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'ZIP creation failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/assets/{id}/lock',
    operationId: 'asset_pilot_lock_asset',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/AssetId'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Asset locked', content: new OA\JsonContent(type: 'object', required: ['message', 'assetId', 'observerWarnings'], properties: [
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId'),
            new OA\Property(property: 'observerWarnings', type: 'array', items: new OA\Items(type: 'string')),
        ])),
        new OA\Response(response: 403, description: 'Asset cannot be published', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'Asset not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Lock failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Delete(
    path: '{prefix}/asset-pilot/assets/{id}/lock',
    operationId: 'asset_pilot_unlock_asset',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/AssetId'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Asset unlocked', content: new OA\JsonContent(type: 'object', required: ['message', 'assetId', 'observerWarnings'], properties: [
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId'),
            new OA\Property(property: 'observerWarnings', type: 'array', items: new OA\Items(type: 'string')),
        ])),
        new OA\Response(response: 403, description: 'Asset cannot be published', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'Asset not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Unlock failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/assets/search',
    operationId: 'asset_pilot_assets_search',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/Page'),
        new OA\Parameter(ref: '#/components/parameters/Limit'),
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'folder', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'objectId', in: 'query', schema: new OA\Schema(ref: '#/components/schemas/PositiveId')),
        new OA\Parameter(name: 'extension', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'referenced', in: 'query', schema: new OA\Schema(type: 'string', enum: ['referenced', 'unreferenced'])),
        new OA\Parameter(ref: '#/components/parameters/Sort'),
        new OA\Parameter(ref: '#/components/parameters/Order'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Asset search', content: new OA\JsonContent(ref: '#/components/schemas/PaginatedAssets')),
        new OA\Response(response: 403, description: 'View permission required, or the objectId filter targets an object the actor cannot view', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/assets/tags',
    operationId: 'asset_pilot_available_tags',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/Page'),
        new OA\Parameter(ref: '#/components/parameters/Limit'),
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Available tags', content: new OA\JsonContent(type: 'object', required: ['items', 'total', 'page', 'limit', 'pages'], properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', required: ['name', 'path'], properties: [
                new OA\Property(property: 'id', type: 'integer', minimum: 1, nullable: true),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'parentId', type: 'integer', minimum: 1, nullable: true),
                new OA\Property(property: 'path', type: 'string'),
            ])),
            new OA\Property(property: 'total', type: 'integer', minimum: 0),
            new OA\Property(property: 'page', type: 'integer', minimum: 1),
            new OA\Property(property: 'limit', type: 'integer', minimum: 1),
            new OA\Property(property: 'pages', type: 'integer', minimum: 0),
        ])),
        new OA\Response(response: 500, description: 'Tag listing failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/assets/bulk-tag',
    operationId: 'asset_pilot_bulk_tag',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['assetIds', 'tagIds'], properties: [
        new OA\Property(property: 'assetIds', ref: '#/components/schemas/IdList'),
        new OA\Property(property: 'tagIds', ref: '#/components/schemas/IdList'),
        new OA\Property(property: 'replace', type: 'boolean', default: false),
        new OA\Property(property: 'dryRun', type: 'boolean', default: false, description: 'Set true to preview and receive a signed planToken.'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required on apply; returned by a matching dry-run preview.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Tag preview or result', content: new OA\JsonContent(type: 'object', required: ['tagged', 'failed', 'errors', 'observerWarnings', 'dryRun', 'planToken', 'eligible'], properties: [
            new OA\Property(property: 'tagged', type: 'integer', minimum: 0),
            new OA\Property(property: 'failed', type: 'integer', minimum: 0),
            new OA\Property(property: 'errors', ref: '#/components/schemas/BulkErrors'),
            new OA\Property(property: 'observerWarnings', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'reference', type: 'string', nullable: true),
            new OA\Property(property: 'dryRun', type: 'boolean'),
            new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Signed, single-use token on preview; null after apply.'),
            new OA\Property(property: 'eligible', type: 'integer', minimum: 0, description: 'Assets that would be mutated (requested minus protected/failed).'),
        ])),
        new OA\Response(response: 400, description: 'Invalid asset or tag IDs', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'An asset cannot be published', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'The preview plan is stale or already applied; run a fresh dry-run', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 422, description: 'Dry-run preview rejected (for example no eligible assets in the selection)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Bulk tagging failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/assets/bulk-property',
    operationId: 'asset_pilot_bulk_property',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['assetIds', 'name'], properties: [
        new OA\Property(property: 'assetIds', ref: '#/components/schemas/IdList'),
        new OA\Property(property: 'name', type: 'string', minLength: 1),
        new OA\Property(property: 'type', type: 'string', enum: ['text', 'bool', 'select'], default: 'text'),
        new OA\Property(property: 'data', nullable: true, oneOf: [
            new OA\Schema(type: 'string'),
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'number'),
            new OA\Schema(type: 'boolean'),
        ]),
        new OA\Property(property: 'dryRun', type: 'boolean', default: false, description: 'Set true to preview and receive a signed planToken.'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required on apply; returned by a matching dry-run preview.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Property preview or result', content: new OA\JsonContent(type: 'object', required: ['updated', 'failed', 'errors', 'observerWarnings', 'dryRun', 'planToken', 'eligible'], properties: [
            new OA\Property(property: 'updated', type: 'integer', minimum: 0),
            new OA\Property(property: 'failed', type: 'integer', minimum: 0),
            new OA\Property(property: 'errors', ref: '#/components/schemas/BulkErrors'),
            new OA\Property(property: 'observerWarnings', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'dryRun', type: 'boolean'),
            new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Signed, single-use token on preview; null after apply.'),
            new OA\Property(property: 'eligible', type: 'integer', minimum: 0, description: 'Assets that would be mutated (requested minus protected/failed).'),
        ])),
        new OA\Response(response: 400, description: 'Invalid property request', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'An asset cannot be published', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'The preview plan is stale or already applied; run a fresh dry-run', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 422, description: 'Dry-run preview rejected (for example no eligible assets in the selection)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'The property mutation failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
class OpenApiAssetManagementSpecification
{
    private function __construct() {}
}

#[OA\Post(
    path: '{prefix}/asset-pilot/operations/reorganize',
    operationId: 'asset_pilot_operations_reorganize',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['folder'], properties: [
        new OA\Property(property: 'folder', type: 'string', minLength: 1),
        new OA\Property(property: 'async', type: 'boolean', default: false),
        new OA\Property(property: 'dryRun', type: 'boolean', default: true, description: 'Preview by default. Set false only with the planToken returned for the same selector and current object state.'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required when dryRun is false; signed, actor-bound, and single-use.'),
        new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 1000, nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Reviewed preview or synchronous reorganization', content: new OA\JsonContent(allOf: [
            new OA\Schema(ref: '#/components/schemas/ReviewedSelectionResult'),
            new OA\Schema(type: 'object', required: ['assetsScanned', 'ownerObjects', 'truncated'], properties: [
                new OA\Property(property: 'assetsScanned', type: 'integer', minimum: 0),
                new OA\Property(property: 'ownerObjects', type: 'integer', minimum: 0),
                new OA\Property(property: 'truncated', type: 'boolean', description: 'True when the authorized folder scan hit its candidate budget.'),
            ]),
        ])),
        new OA\Response(response: 202, description: 'Reviewed reorganization queued as one operation run', content: new OA\JsonContent(allOf: [
            new OA\Schema(ref: '#/components/schemas/ReviewedSelectionResult'),
            new OA\Schema(type: 'object', required: ['assetsScanned', 'ownerObjects', 'truncated'], properties: [
                new OA\Property(property: 'assetsScanned', type: 'integer', minimum: 0),
                new OA\Property(property: 'ownerObjects', type: 'integer', minimum: 0),
                new OA\Property(property: 'truncated', type: 'boolean', description: 'True when the authorized folder scan hit its candidate budget.'),
            ]),
        ])),
        new OA\Response(response: 400, description: 'Invalid folder, JSON, option type, selection size, or plan token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Operate or object publish permission required', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'Preview plan is stale or already claimed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Synchronous reorganization failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/operations/simulate',
    operationId: 'asset_pilot_operations_simulate',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['objectId'], properties: [
        new OA\Property(property: 'objectId', type: 'integer', minimum: 1, description: 'The data object whose organization is simulated and recorded as a terminal run.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Simulation recorded (no mutation); the run holds the previewed moves', content: new OA\JsonContent(type: 'object', required: ['runId', 'operations'], properties: [
            new OA\Property(property: 'runId', type: 'string', nullable: true, pattern: '^[a-f0-9]{32}$', description: 'The recorded simulation run id, or null when nothing would move.'),
            new OA\Property(property: 'operations', type: 'array', items: new OA\Items(ref: '#/components/schemas/MoveOperationPreview')),
            new OA\Property(property: 'message', type: 'string', nullable: true),
        ])),
        new OA\Response(response: 400, description: 'Invalid JSON or objectId', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'View or object access permission required', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'Object not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/operations/replay',
    operationId: 'asset_pilot_operations_replay',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object', properties: [
        new OA\Property(property: 'since', type: 'string'),
        new OA\Property(property: 'rule', type: 'string'),
        new OA\Property(property: 'class', type: 'string'),
        new OA\Property(property: 'async', type: 'boolean', default: false),
        new OA\Property(property: 'dryRun', type: 'boolean', default: true, description: 'Preview by default. Set false only with the planToken returned for the same filters and current object state.'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required when dryRun is false; signed, actor-bound, and single-use.'),
        new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 1000, nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Reviewed preview or synchronous replay', content: new OA\JsonContent(allOf: [
            new OA\Schema(ref: '#/components/schemas/ReviewedSelectionResult'),
            new OA\Schema(type: 'object', required: ['candidates'], properties: [
                new OA\Property(property: 'candidates', type: 'integer', minimum: 0),
            ]),
        ])),
        new OA\Response(response: 202, description: 'Reviewed replay queued as one operation run', content: new OA\JsonContent(allOf: [
            new OA\Schema(ref: '#/components/schemas/ReviewedSelectionResult'),
            new OA\Schema(type: 'object', required: ['candidates'], properties: [
                new OA\Property(property: 'candidates', type: 'integer', minimum: 0),
            ]),
        ])),
        new OA\Response(response: 400, description: 'Invalid JSON, date, option type, selection size, or plan token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Operate or object publish permission required', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'Preview plan is stale or already claimed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Synchronous replay failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/organize/explain',
    operationId: 'asset_pilot_organize_explain',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['objectId'], properties: [new OA\Property(property: 'objectId', ref: '#/components/schemas/PositiveId')])),
    responses: [
        new OA\Response(response: 200, description: 'Rule explanation', content: new OA\JsonContent(type: 'object', required: ['objectId', 'operations', 'evaluations'], properties: [
            new OA\Property(property: 'objectId', ref: '#/components/schemas/PositiveId'),
            new OA\Property(property: 'operations', type: 'array', items: new OA\Items(ref: '#/components/schemas/MoveOperationPreview')),
            new OA\Property(property: 'evaluations', type: 'array', items: new OA\Items(type: 'object', required: ['assetId', 'assetPath', 'fieldName', 'ruleName', 'matched', 'priority', 'enabled'], properties: [
                new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId'),
                new OA\Property(property: 'assetPath', type: 'string'),
                new OA\Property(property: 'fieldName', type: 'string'),
                new OA\Property(property: 'locale', type: 'string', nullable: true),
                new OA\Property(property: 'ruleName', type: 'string'),
                new OA\Property(property: 'matched', type: 'boolean'),
                new OA\Property(property: 'rejectionReason', type: 'string', nullable: true),
                new OA\Property(property: 'conditionExpression', type: 'string', nullable: true),
                new OA\Property(property: 'conditionResult', type: 'boolean', nullable: true),
                new OA\Property(property: 'conditionError', type: 'string', nullable: true),
                new OA\Property(property: 'filterDetails', type: 'string', nullable: true),
                new OA\Property(property: 'resolvedPath', type: 'string', nullable: true),
                new OA\Property(property: 'priority', type: 'integer'),
                new OA\Property(property: 'enabled', type: 'boolean'),
            ])),
        ])),
        new OA\Response(response: 400, description: 'Invalid object ID', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Object is not viewable', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'Object not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/organize',
    operationId: 'asset_pilot_organize',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['objectId'], properties: [
        new OA\Property(property: 'objectId', ref: '#/components/schemas/PositiveId'),
        new OA\Property(property: 'dryRun', type: 'boolean', default: false, description: 'Set true to preview and receive a signed, single-use planToken.'),
        new OA\Property(property: 'async', type: 'boolean', default: false),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required when dryRun is false; must come from a matching preview for the same object, actor, configuration, and current operations.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Organization or dry-run result', content: new OA\JsonContent(oneOf: [
            new OA\Schema(type: 'object', required: ['runId', 'results'], properties: [
                new OA\Property(property: 'runId', type: 'string'),
                new OA\Property(property: 'results', type: 'array', items: new OA\Items(ref: '#/components/schemas/OperationResult')),
            ]),
            new OA\Schema(type: 'object', required: ['dryRun', 'planToken', 'operations'], properties: [
                new OA\Property(property: 'dryRun', type: 'boolean', enum: [true]),
                new OA\Property(property: 'planToken', type: 'string', minLength: 1),
                new OA\Property(property: 'operations', type: 'array', items: new OA\Items(ref: '#/components/schemas/MoveOperationPreview')),
            ]),
        ])),
        new OA\Response(response: 202, description: 'Organization accepted', content: new OA\JsonContent(type: 'object', required: ['message', 'runId', 'statusUrl'], properties: [
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'runId', type: 'string'),
            new OA\Property(property: 'statusUrl', type: 'string', pattern: '^/'),
        ])),
        new OA\Response(response: 400, description: 'Invalid object ID, JSON, or plan token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Object cannot be published', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'Object not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'Preview plan is stale or already claimed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Organization failed; the response body carries the runId of the failed run', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/organize/bulk',
    operationId: 'asset_pilot_organize_bulk',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', anyOf: [
        new OA\Schema(required: ['className']),
        new OA\Schema(required: ['objectIds']),
    ], properties: [
        new OA\Property(property: 'className', type: 'string', minLength: 1),
        new OA\Property(property: 'objectIds', ref: '#/components/schemas/IdList'),
        new OA\Property(property: 'async', type: 'boolean', default: true),
        new OA\Property(property: 'dryRun', type: 'boolean', default: false, description: 'Set true to preview the resolved object set and receive a signed, single-use planToken.'),
        new OA\Property(property: 'batchSize', type: 'integer', minimum: 1, maximum: 1000),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required when dryRun is false; bound to the exact selector, resolved objects, actor, configuration, and current operations.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Bulk organization or preview result', content: new OA\JsonContent(oneOf: [
            new OA\Schema(type: 'object', required: ['dryRun', 'planToken', 'objectCount', 'operations'], properties: [
                new OA\Property(property: 'dryRun', type: 'boolean', enum: [true]),
                new OA\Property(property: 'planToken', type: 'string', minLength: 1),
                new OA\Property(property: 'objectCount', type: 'integer', minimum: 1),
                new OA\Property(property: 'operations', type: 'array', items: new OA\Items(ref: '#/components/schemas/MoveOperationPreview')),
            ]),
            new OA\Schema(type: 'object', required: ['runId', 'objectCount', 'resultCount', 'objectCounts', 'objectResults', 'observerWarnings'], properties: [
                new OA\Property(property: 'runId', type: 'string'),
                new OA\Property(property: 'objectCount', type: 'integer', minimum: 0),
                new OA\Property(property: 'resultCount', type: 'integer', minimum: 0),
                new OA\Property(property: 'objectCounts', type: 'object', required: ['attempted', 'succeeded', 'skipped', 'failed'], properties: [
                    new OA\Property(property: 'attempted', type: 'integer', minimum: 0),
                    new OA\Property(property: 'succeeded', type: 'integer', minimum: 0),
                    new OA\Property(property: 'skipped', type: 'integer', minimum: 0),
                    new OA\Property(property: 'failed', type: 'integer', minimum: 0),
                ]),
                new OA\Property(property: 'objectResults', type: 'array', items: new OA\Items(type: 'object', required: ['objectId', 'status', 'operationCount'], properties: [
                    new OA\Property(property: 'objectId', ref: '#/components/schemas/PositiveId'),
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                    new OA\Property(property: 'operationCount', type: 'integer', minimum: 0),
                ])),
                new OA\Property(property: 'observerWarnings', type: 'array', items: new OA\Items(type: 'string')),
            ]),
        ])),
        new OA\Response(response: 202, description: 'Bulk organization accepted', content: new OA\JsonContent(type: 'object', required: ['message', 'runId', 'statusUrl', 'objectCount', 'batchCount'], properties: [
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'runId', type: 'string'),
            new OA\Property(property: 'statusUrl', type: 'string', pattern: '^/'),
            new OA\Property(property: 'objectCount', type: 'integer', minimum: 1),
            new OA\Property(property: 'batchCount', type: 'integer', minimum: 1),
        ])),
        new OA\Response(response: 400, description: 'Invalid or oversized request or plan token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'An object cannot be published', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'Preview plan is stale or already claimed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'No matching objects found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Bulk organization failed; the response body carries the runId of the failed run', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/operations/bulk-preview',
    operationId: 'asset_pilot_bulk_preview',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['className'], properties: [
        new OA\Property(property: 'className', type: 'string', minLength: 1),
        new OA\Property(property: 'page', type: 'integer', minimum: 1, default: 1),
        new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 200, default: 50),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Bulk candidate preview', content: new OA\JsonContent(type: 'object', required: ['objects', 'page', 'hasMore', 'truncated'], properties: [
            new OA\Property(property: 'objects', type: 'array', items: new OA\Items(type: 'object', required: ['id', 'key'], properties: [
                new OA\Property(property: 'id', ref: '#/components/schemas/PositiveId'),
                new OA\Property(property: 'key', type: 'string'),
                new OA\Property(property: 'className', type: 'string', nullable: true),
            ])),
            new OA\Property(property: 'total', type: 'integer', minimum: 0, nullable: true, description: 'Always null for this interactive View endpoint: the exact authorized total is never cheaply available (the supervised System bypass cannot apply to an HTTP route), so it is withheld and hasMore is authoritative.'),
            new OA\Property(property: 'page', type: 'integer', minimum: 1),
            new OA\Property(property: 'pages', type: 'integer', minimum: 0, nullable: true, description: 'Always null: total is withheld, so use hasMore for cursor pagination.'),
            new OA\Property(property: 'hasMore', type: 'boolean', description: 'A further authorized object exists past this page.'),
            new OA\Property(property: 'truncated', type: 'boolean', description: 'True when the bounded object scan hit its candidate budget before filling the page.'),
        ])),
        new OA\Response(response: 400, description: 'Invalid class or JSON', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/operations/status',
    operationId: 'asset_pilot_operations_status',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    responses: [
        new OA\Response(response: 200, description: 'Operation status', content: new OA\JsonContent(type: 'object', required: ['stats', 'recentOperations'], properties: [
            new OA\Property(property: 'stats', type: 'object', additionalProperties: true),
            new OA\Property(property: 'recentOperations', type: 'array', items: new OA\Items(ref: '#/components/schemas/AuditEntry')),
        ])),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/operations/runs',
    operationId: 'asset_pilot_operation_run_list',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        new OA\Parameter(name: 'kind', in: 'query', required: false, description: 'Filter to one operation run kind (for example simulation).', schema: new OA\Schema(type: 'string', enum: ['organize', 'reorganize', 'replay', 'duplicate-merge', 'simulation'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Actor-scoped recent operation runs', content: new OA\JsonContent(type: 'object', required: ['items', 'limit'], properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/OperationRunSummary')),
            new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 100),
        ])),
        new OA\Response(response: 400, description: 'Limit or kind filter is invalid', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/operations/runs/{id}',
    operationId: 'asset_pilot_operation_run_get',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/OperationRunId'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Actor-scoped operation run', content: new OA\JsonContent(ref: '#/components/schemas/OperationRun')),
        new OA\Response(response: 403, description: 'View permission required'),
        new OA\Response(response: 404, description: 'Run not found or not visible to this actor', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/operations/runs/{id}/cancel',
    operationId: 'asset_pilot_operation_run_cancel',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/OperationRunId'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Pending or queued run cancelled immediately', content: new OA\JsonContent(type: 'object', required: ['runId', 'status'], properties: [
            new OA\Property(property: 'runId', type: 'string'),
            new OA\Property(property: 'status', type: 'string', enum: ['cancelled']),
        ])),
        new OA\Response(response: 202, description: 'Cancellation requested', content: new OA\JsonContent(type: 'object', required: ['runId', 'status'], properties: [
            new OA\Property(property: 'runId', type: 'string'),
            new OA\Property(property: 'status', type: 'string', enum: ['cancel_requested']),
        ])),
        new OA\Response(response: 403, description: 'Operate permission required'),
        new OA\Response(response: 404, description: 'Run not found or not visible to this actor', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'Run is already terminal', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/operations/runs/{id}/retry',
    operationId: 'asset_pilot_operation_run_retry',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/OperationRunId'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Retry completed synchronously as a new run', content: new OA\JsonContent(type: 'object', required: ['runId', 'retryOf', 'status', 'statusUrl'], properties: [
            new OA\Property(property: 'runId', type: 'string'),
            new OA\Property(property: 'retryOf', type: 'string'),
            new OA\Property(property: 'status', type: 'string', enum: ['cancelled', 'completed', 'blocked', 'partial', 'failed']),
            new OA\Property(property: 'statusUrl', type: 'string', pattern: '^/'),
            new OA\Property(property: 'dispositions', type: 'array', items: new OA\Items(type: 'object', required: ['copyId', 'outcome', 'reason'], properties: [
                new OA\Property(property: 'copyId', ref: '#/components/schemas/PositiveId'),
                new OA\Property(property: 'outcome', type: 'string', enum: ['quarantined', 'deleted', 'left_referenced', 'left_error', 'blocked', 'skipped']),
                new OA\Property(property: 'reason', type: 'string'),
            ])),
        ])),
        new OA\Response(response: 202, description: 'Retry queued as a new run', content: new OA\JsonContent(type: 'object', required: ['runId', 'retryOf', 'status', 'statusUrl'], properties: [
            new OA\Property(property: 'runId', type: 'string'),
            new OA\Property(property: 'retryOf', type: 'string'),
            new OA\Property(property: 'status', type: 'string', enum: ['queued']),
            new OA\Property(property: 'statusUrl', type: 'string', pattern: '^/'),
        ])),
        new OA\Response(response: 403, description: 'Operate permission required'),
        new OA\Response(response: 404, description: 'Run not found or not visible to this actor', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'No retryable items', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Retry failed; the response body carries the runId of the failed retry run', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
class OpenApiOperationsSpecification
{
    private function __construct() {}
}

#[OA\Get(
    path: '{prefix}/asset-pilot/metrics',
    operationId: 'asset_pilot_metrics',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    responses: [
        new OA\Response(response: 200, description: 'Metrics', content: new OA\JsonContent(type: 'object', additionalProperties: true)),
        new OA\Response(response: 500, description: 'Metrics collection failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/audit',
    operationId: 'asset_pilot_audit',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/Page'),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        new OA\Parameter(name: 'class', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'in_progress', 'recovery_required', 'completed', 'completed_with_observer_error', 'skipped', 'failed'])),
        new OA\Parameter(name: 'ruleName', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(ref: '#/components/parameters/Sort'),
        new OA\Parameter(ref: '#/components/parameters/Order'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Audit rows', content: new OA\JsonContent(ref: '#/components/schemas/PaginatedAudit')),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/audit/export',
    operationId: 'asset_pilot_audit_export',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(name: 'class', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'in_progress', 'recovery_required', 'completed', 'completed_with_observer_error', 'skipped', 'failed'])),
        new OA\Parameter(name: 'ruleName', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Audit CSV export', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/audit/{id}/revert',
    operationId: 'asset_pilot_audit_revert',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/AssetId'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Revert result', content: new OA\JsonContent(type: 'object', required: ['message', 'newPath'], properties: [
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'newPath', type: 'string'),
            new OA\Property(property: 'warning', type: 'string', nullable: true),
        ])),
        new OA\Response(response: 400, description: 'Operation is not completed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Revert is not permitted', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'Audit entry or asset not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'Original path is occupied', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Revert failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
class OpenApiMetricsAndAuditSpecification
{
    private function __construct() {}
}

#[OA\Get(
    path: '{prefix}/asset-pilot/integrity',
    operationId: 'asset_pilot_integrity',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(name: 'ids', in: 'query', description: 'Comma-separated positive asset IDs; at most 50.', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(ref: '#/components/parameters/Page'),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
        new OA\Parameter(name: 'folder', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'extension', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Integrity results', content: new OA\JsonContent(type: 'object', required: ['items', 'scanned', 'broken', 'page', 'limit', 'hasNext'], properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/IntegrityItem')),
            new OA\Property(property: 'scanned', type: 'integer', minimum: 0),
            new OA\Property(property: 'broken', type: 'integer', minimum: 0),
            new OA\Property(property: 'page', type: 'integer', minimum: 1),
            new OA\Property(property: 'limit', type: 'integer', minimum: 0, maximum: 50),
            new OA\Property(property: 'hasNext', type: 'boolean'),
        ])),
        new OA\Response(response: 400, description: 'Invalid or oversized ID list', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Integrity scan failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/integrity/heal',
    operationId: 'asset_pilot_integrity_heal',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['ids'], properties: [
        new OA\Property(property: 'ids', type: 'array', minItems: 1, maxItems: 50, uniqueItems: true, items: new OA\Items(ref: '#/components/schemas/PositiveId')),
        new OA\Property(property: 'dryRun', type: 'boolean', default: false, description: 'Set true to preview the exact sorted asset set and receive a signed, single-use planToken.'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required when dryRun is false; must come from a matching preview for the same actor, sorted asset IDs, effective integrity configuration, asset/version state, and heal results.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Heal preview or applied result', content: new OA\JsonContent(type: 'object', required: ['dryRun', 'planToken', 'results'], properties: [
            new OA\Property(property: 'dryRun', type: 'boolean'),
            new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Signed token on preview; null after a successful apply.'),
            new OA\Property(property: 'results', type: 'array', items: new OA\Items(type: 'object', required: ['assetId', 'outcome', 'observerWarnings'], properties: [
                new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId'),
                new OA\Property(property: 'outcome', type: 'string'),
                new OA\Property(property: 'toVersion', type: 'integer', minimum: 1, nullable: true),
                new OA\Property(property: 'checker', type: 'string', nullable: true),
                new OA\Property(property: 'reason', type: 'string', nullable: true),
                new OA\Property(property: 'observerWarnings', type: 'array', items: new OA\Items(type: 'string')),
            ])),
        ])),
        new OA\Response(response: 400, description: 'Invalid request, missing plan token, or malformed plan token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'Preview race, stale plan, changed target, or previously consumed token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Heal failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/integrity/history',
    operationId: 'asset_pilot_integrity_history',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/Page'),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Reversible heal history', content: new OA\JsonContent(type: 'object', required: ['items', 'total', 'page', 'pages'], properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/IntegrityHealHistoryItem')),
            new OA\Property(property: 'total', type: 'integer', minimum: 0, nullable: true, description: 'Null when the actor is workspace-scoped: the authorized total is withheld and hasMore is authoritative.'),
            new OA\Property(property: 'page', type: 'integer', minimum: 1),
            new OA\Property(property: 'pages', type: 'integer', minimum: 0, nullable: true),
            new OA\Property(property: 'hasMore', type: 'boolean'),
            new OA\Property(property: 'truncated', type: 'boolean', description: 'True when the bounded scan stopped before exhausting history.'),
        ])),
        new OA\Response(response: 403, description: 'Admin permission required'),
        new OA\Response(response: 500, description: 'Heal history lookup failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/integrity/undo',
    operationId: 'asset_pilot_integrity_undo',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['assetId'], properties: [new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId')])),
    responses: [
        new OA\Response(response: 200, description: 'Undo result', content: new OA\JsonContent(type: 'object', required: ['assetId', 'undone'], properties: [
            new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId'),
            new OA\Property(property: 'undone', type: 'boolean', enum: [true]),
        ])),
        new OA\Response(response: 400, description: 'Invalid asset ID', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'No reversible heal found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Undo failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
class OpenApiIntegritySpecification
{
    private function __construct() {}
}

#[OA\Get(
    path: '{prefix}/asset-pilot/folders/empty',
    operationId: 'asset_pilot_folders_empty',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/Page'),
        new OA\Parameter(ref: '#/components/parameters/Limit'),
        new OA\Parameter(name: 'folder', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Empty folders', content: new OA\JsonContent(type: 'object', required: ['items', 'page', 'limit', 'hasMore'], properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', required: ['id', 'path'], properties: [
                new OA\Property(property: 'id', ref: '#/components/schemas/PositiveId'),
                new OA\Property(property: 'path', type: 'string'),
            ])),
            new OA\Property(property: 'page', type: 'integer', minimum: 1),
            new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 200),
            new OA\Property(property: 'hasMore', type: 'boolean', description: 'This listing has no total; page again while hasMore is true.'),
        ])),
        new OA\Response(response: 500, description: 'Empty-folder scan failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/folders/empty/delete',
    operationId: 'asset_pilot_folders_empty_delete',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['ids'], properties: [
        new OA\Property(property: 'ids', type: 'array', minItems: 1, maxItems: 200, uniqueItems: true, items: new OA\Items(ref: '#/components/schemas/PositiveId')),
        new OA\Property(property: 'dryRun', type: 'boolean', default: false, description: 'Set true to preview and receive a planToken; false (default) applies the reviewed deletion and requires planToken.'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'The signed token returned by a fresh dry-run preview; required to apply (dryRun false).'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Folder deletion preview or result', content: new OA\JsonContent(type: 'object', required: ['deleted', 'skipped', 'failed', 'errors', 'dryRun', 'planToken'], properties: [
            new OA\Property(property: 'deleted', type: 'integer', minimum: 0),
            new OA\Property(property: 'eligible', type: 'integer', minimum: 0, description: 'Folders eligible for deletion; present on a dry-run preview.'),
            new OA\Property(property: 'skipped', type: 'integer', minimum: 0),
            new OA\Property(property: 'failed', type: 'integer', minimum: 0),
            new OA\Property(property: 'errors', ref: '#/components/schemas/BulkErrors'),
            new OA\Property(property: 'dryRun', type: 'boolean'),
            new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Signed token from a dry-run preview; null on apply.'),
        ])),
        new OA\Response(response: 400, description: 'Invalid or oversized ID list, or a missing plan token on apply', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 403, description: 'Operate permission required', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'A folder changed during preview, or the plan is stale or already used', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Folder deletion failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/storage/trends',
    operationId: 'asset_pilot_storage_trends',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 365, default: 90)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Storage trends', content: new OA\JsonContent(type: 'object', required: ['type', 'items'], properties: [
            new OA\Property(property: 'type', type: 'string', nullable: true),
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', required: ['capturedAt', 'count', 'size', 'unknownSizeCount'], properties: [
                new OA\Property(property: 'capturedAt', type: 'string', format: 'date-time'),
                new OA\Property(property: 'count', type: 'integer', minimum: 0),
                new OA\Property(property: 'size', type: 'integer', minimum: 0),
                new OA\Property(property: 'unknownSizeCount', type: 'integer', minimum: 0),
            ])),
        ])),
        new OA\Response(response: 500, description: 'Storage trend lookup failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/operations/recovery',
    operationId: 'asset_pilot_operation_recovery',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object', properties: [
        new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 1000, default: 100),
        new OA\Property(property: 'apply', type: 'boolean', default: false, description: 'False previews stale operations and returns a signed plan token. True finalizes the exact reviewed classifications without repeating asset mutations.'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required when apply is true and bound to the authenticated actor, recovery configuration, limit, operation IDs, and classifications.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Recovery preview or applied result', content: new OA\JsonContent(type: 'object', required: ['applied', 'planToken', 'unresolved', 'results'], properties: [
            new OA\Property(property: 'applied', type: 'boolean'),
            new OA\Property(property: 'planToken', type: 'string', nullable: true),
            new OA\Property(property: 'unresolved', type: 'integer', minimum: 0),
            new OA\Property(property: 'results', type: 'array', items: new OA\Items(ref: '#/components/schemas/OperationRecoveryResult')),
        ])),
        new OA\Response(response: 400, description: 'Invalid input or malformed plan token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'Stale, changed, expired, or already consumed plan', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Operation recovery failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/operations/deliveries/retry',
    operationId: 'asset_pilot_operation_delivery_retry',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object', properties: [
        new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 1000, default: 100),
        new OA\Property(property: 'apply', type: 'boolean', default: false, description: 'False previews the exact dead delivery rows and returns a signed plan token. True requeues only that reviewed scope.'),
        new OA\Property(property: 'planToken', type: 'string', nullable: true, description: 'Required when apply is true and bound to the authenticated actor, retry configuration, limit, delivery IDs, and row fingerprints.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Dead delivery retry preview or applied result', content: new OA\JsonContent(ref: '#/components/schemas/DeliveryRetryReview')),
        new OA\Response(response: 400, description: 'Invalid input or malformed plan token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 409, description: 'Stale, changed, expired, or already consumed plan', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Dead operation delivery retry failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
class OpenApiMaintenanceSpecification
{
    private function __construct() {}
}

#[OA\Get(
    path: '{prefix}/asset-pilot/quarantine',
    operationId: 'asset_pilot_quarantine_list',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/Page'),
        new OA\Parameter(ref: '#/components/parameters/Limit'),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'before', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'after', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Quarantine records', content: new OA\JsonContent(allOf: [
            new OA\Schema(ref: '#/components/schemas/Pagination'),
            new OA\Schema(type: 'object', required: ['items'], properties: [new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', required: ['asset_id', 'original_path', 'quarantined_at', 'path', 'filename', 'type'], properties: [
                new OA\Property(property: 'asset_id', ref: '#/components/schemas/PositiveId'),
                new OA\Property(property: 'original_path', type: 'string'),
                new OA\Property(property: 'quarantined_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'path', type: 'string'),
                new OA\Property(property: 'filename', type: 'string'),
                new OA\Property(property: 'type', type: 'string'),
                new OA\Property(property: 'mimetype', type: 'string', nullable: true),
            ]))]),
        ])),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/quarantine/export',
    operationId: 'asset_pilot_quarantine_export',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'before', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'after', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Quarantine CSV export', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
        new OA\Response(response: 403, description: 'View permission required'),
    ],
)]
#[OA\Post(
    path: '{prefix}/asset-pilot/quarantine/{assetId}/restore',
    operationId: 'asset_pilot_quarantine_restore',
    tags: ['Asset Pilot'],
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/StudioPrefix'),
        new OA\Parameter(ref: '#/components/parameters/QuarantineAssetId'),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Restore result', content: new OA\JsonContent(type: 'object', required: ['message', 'assetId'], properties: [
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'assetId', ref: '#/components/schemas/PositiveId'),
        ])),
        new OA\Response(response: 403, description: 'Restore is not permitted', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 404, description: 'Asset is not quarantined or no longer exists', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        new OA\Response(response: 500, description: 'Restore failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
class OpenApiQuarantineSpecification
{
    private function __construct() {}
}

#[OA\Get(
    path: '{prefix}/asset-pilot/dashboard',
    operationId: 'asset_pilot_dashboard',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    responses: [
        new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(type: 'object', required: ['totalOrganized', 'totalOrganizedWithWarnings', 'totalPending', 'totalFailed', 'totalSkipped', 'rulesCount', 'recentOperations', 'operationsByClass'], properties: [
            new OA\Property(property: 'totalOrganized', type: 'integer', minimum: 0),
            new OA\Property(property: 'totalOrganizedWithWarnings', type: 'integer', minimum: 0),
            new OA\Property(property: 'totalPending', type: 'integer', minimum: 0),
            new OA\Property(property: 'totalFailed', type: 'integer', minimum: 0),
            new OA\Property(property: 'totalSkipped', type: 'integer', minimum: 0),
            new OA\Property(property: 'rulesCount', type: 'integer', minimum: 0),
            new OA\Property(property: 'recentOperations', type: 'array', items: new OA\Items(ref: '#/components/schemas/AuditEntry')),
            new OA\Property(property: 'operationsByClass', type: 'object', additionalProperties: true),
        ])),
        new OA\Response(response: 500, description: 'Dashboard lookup failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
#[OA\Get(
    path: '{prefix}/asset-pilot/dashboard/class-stats',
    operationId: 'asset_pilot_dashboard_class_stats',
    tags: ['Asset Pilot'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/StudioPrefix')],
    responses: [
        new OA\Response(response: 200, description: 'Per-class operation statistics', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object', required: ['className', 'total', 'completed', 'completed_with_observer_error', 'failed', 'skipped', 'ruleCount'], properties: [
            new OA\Property(property: 'className', type: 'string'),
            new OA\Property(property: 'total', type: 'integer', minimum: 0),
            new OA\Property(property: 'completed', type: 'integer', minimum: 0),
            new OA\Property(property: 'completed_with_observer_error', type: 'integer', minimum: 0),
            new OA\Property(property: 'failed', type: 'integer', minimum: 0),
            new OA\Property(property: 'skipped', type: 'integer', minimum: 0),
            new OA\Property(property: 'ruleCount', type: 'integer', minimum: 0),
        ]))),
        new OA\Response(response: 500, description: 'Class statistics lookup failed', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
    ],
)]
class OpenApiDashboardSpecification
{
    private function __construct() {}
}
