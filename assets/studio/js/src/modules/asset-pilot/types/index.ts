export interface DashboardData {
  totalOrganized: number
  totalPending: number
  totalFailed: number
  totalSkipped: number
  rulesCount: number
  recentOperations: AuditEntry[]
  operationsByClass: Record<string, number>
}

export interface RuleData {
  name: string
  class: string
  fields: string[]
  condition: string | null
  targetPath: string
  strategy: string
  priority: number
  enabled: boolean
  filters: Record<string, unknown>
}

export interface RuleDetail extends RuleData {
  stats: Record<string, number>
}

export interface ClassStat {
  className: string
  total: number
  completed: number
  failed: number
  skipped: number
  ruleCount: number
}

export interface MoveOperation {
  assetId: number
  sourcePath: string
  targetPath: string
  ruleName: string
  objectId?: number
  objectClass?: string
  status?: string
}

export interface RulePreviewResponse {
  operations: MoveOperation[]
  planToken: string
}

export interface OrganizePreviewRequest {
  objectId: number
  dryRun: true
  async?: boolean
}

export interface OrganizeApplyRequest {
  objectId: number
  dryRun?: false
  async?: boolean
  planToken: string
}

export type OrganizeRequest = OrganizePreviewRequest | OrganizeApplyRequest

interface BulkOrganizeSelector {
  className?: string
  objectIds?: number[]
  batchSize?: number
}

export interface BulkOrganizePreviewRequest extends BulkOrganizeSelector {
  dryRun: true
  async?: boolean
}

export interface BulkOrganizeApplyRequest extends BulkOrganizeSelector {
  dryRun?: false
  async?: boolean
  planToken: string
}

export type BulkOrganizeRequest = BulkOrganizePreviewRequest | BulkOrganizeApplyRequest

export interface OrganizeResponse {
  dryRun?: boolean
  planToken?: string
  operations?: MoveOperation[]
  results?: OperationResult[]
  message?: string
  runId?: string
  statusUrl?: string
  objectCount?: number
  batchCount?: number
  observerWarnings?: string[]
}

export type OperationRunKind = 'organize' | 'reorganize' | 'replay' | 'duplicate-merge'

export type OperationRunStatus =
  | 'pending_dispatch'
  | 'queued'
  | 'running'
  | 'cancel_requested'
  | 'cancelled'
  | 'completed'
  | 'blocked'
  | 'partial'
  | 'failed'

export type OperationRunItemStatus = 'queued' | 'running' | 'completed' | 'blocked' | 'skipped' | 'failed' | 'cancelled'

export interface OperationRunItem {
  key: string
  targetType: string
  targetId: number | null
  fingerprint: string | null
  status: OperationRunItemStatus
  attempts: number
  state: Record<string, unknown>
  result: Record<string, unknown> | null
  error: string | null
  createdAt: string
  updatedAt: string
  completedAt: string | null
}

export interface OperationRunSummary {
  id: string
  kind: OperationRunKind
  status: OperationRunStatus
  totalCount: number
  processedCount: number
  succeededCount: number
  skippedCount: number
  blockedCount: number
  failedCount: number
  attempt: number
  retryOf: string | null
  request: Record<string, unknown>
  error: string | null
  createdAt: string
  startedAt: string | null
  updatedAt: string
  completedAt: string | null
}

export interface OperationRun extends OperationRunSummary {
  items: OperationRunItem[]
}

export interface OperationRunListResponse {
  items: OperationRunSummary[]
  limit: number
}

export interface OperationRunMutationResponse {
  runId: string
  status: 'cancel_requested' | 'cancelled'
}

export interface OperationRunRetryResponse {
  runId: string
  retryOf: string
  status: OperationRunStatus
  statusUrl: string
  dispositions?: MergeDisposition[]
}

export type OperationRecoveryKind = 'move' | 'revert'
export type OperationRecoveryClassification = 'completed' | 'failed' | 'recovery_required'

export interface OperationRecoveryResult {
  operationId: number
  assetId: number
  kind: OperationRecoveryKind
  classification: OperationRecoveryClassification
  journalUpdated: boolean
  message: string
}

export interface OperationRecoveryResponse {
  applied: boolean
  planToken: string | null
  unresolved: number
  results: OperationRecoveryResult[]
}

export type OperationDeliveryOutcome = 'success' | 'failure'

export interface DeadOperationDelivery {
  deliveryId: string
  operationId: number
  deliveryKey: string
  observerId: string
  outcome: OperationDeliveryOutcome
  attempts: number
  lastError: string | null
  updatedAt: string
  fingerprint: string
}

export interface DeliveryRetryResponse {
  applied: boolean
  planToken: string | null
  count: number
  deliveries: DeadOperationDelivery[]
}

export interface OperationResult {
  status: string
  message: string
  operation: MoveOperation | null
}

export interface AuditEntry {
  id: number
  asset_id: number
  asset_path_from: string
  asset_path_to: string
  object_id: number
  object_class: string
  rule_name: string
  trigger_type: string
  status: string
  error_message: string | null
  duration_ms: number | null
  user_id: number | null
  created_at: string
  operation_kind: 'move' | 'revert' | null
  actor_type: 'user' | 'system' | 'anonymous' | null
  parent_audit_id: number | null
  schema_version: number | null
  updated_at: string | null
  committed_at: string | null
}

export interface PaginatedAuditResponse {
  items: AuditEntry[]
  total: number
  page: number
  pages: number
}

export interface AuditFilters {
  class?: string
  status?: string
  ruleName?: string
  page?: number
  limit?: number
  sort?: string
  order?: 'asc' | 'desc'
}

export interface BulkPreviewObject {
  id: number
  key: string
  className: string
}

export interface BulkPreviewResponse {
  objects: BulkPreviewObject[]
  total: number
  page: number
  pages: number
}

// Unused Assets
export type ConfidenceLevel = 'definitely_unused' | 'probably_unused' | 'recently_uploaded' | 'historically_used' | 'protected'

export interface AssetItem {
  id: number
  path: string
  filename: string
  type: string
  mimetype: string | null
  file_size: number | null
  size_known: boolean
  created_at: string | null
  modified_at: string | null
  full_path: string
  locked?: boolean
}

export interface UnusedAsset extends AssetItem {
  confidence: ConfidenceLevel
}

export interface UnusedAssetFilters {
  type?: string
  extension?: string
  before?: string
  after?: string
  folder?: string
  confidence?: string
  page?: number
  limit?: number
  sort?: string
  order?: 'asc' | 'desc'
}

export interface PaginatedUnusedResponse {
  items: UnusedAsset[]
  total: number | null
  page: number
  pages: number | null
  hasMore: boolean
  truncated: boolean
}

export interface PaginatedAssetResponse {
  items: AssetItem[]
  total: number | null
  page: number
  pages: number | null
  hasMore: boolean
  truncated: boolean
}

export interface UnusedAssetStats {
  totalCount: number
  totalSize: number
  totalSizeFormatted: string
  unknownSizeCount: number
  byType: Array<{ type: string; count: number; total_size: number; unknown_size_count: number }>
}

export interface BulkActionResult {
  deleted?: number
  moved?: number
  quarantined?: number
  tagged?: number
  updated?: number
  failed: number
  errors: Record<number | string, string>
  observerWarnings?: string[]
}

export interface PlannedBulkActionResult extends BulkActionResult {
  dryRun: boolean
  planToken: string | null
  eligible: number
}

export interface MutationFeedback {
  severity: 'success' | 'warning' | 'error'
  message: string
}

// Explain / Rule Evaluation
export interface RuleEvaluation {
  assetId: number
  assetPath: string
  fieldName: string
  locale: string | null
  ruleName: string
  matched: boolean
  rejectionReason: string | null
  conditionExpression: string | null
  conditionResult: boolean | null
  conditionError: string | null
  filterDetails: string | null
  resolvedPath: string | null
  priority: number
  enabled: boolean
}

export interface ExplainOperation {
  assetId: number
  sourcePath: string
  targetPath: string
  ruleName: string
  status: string
}

export interface ExplainResponse {
  objectId: number
  operations: ExplainOperation[]
  evaluations: RuleEvaluation[]
}

// Asset Management
export interface TagItem {
  id: number
  name: string
  parentId: number | null
  path: string
}

export interface TagSearchResponse {
  items: TagItem[]
  total: number
  page: number
  limit: number
  pages: number
}

export interface AssetSearchFilters {
  q?: string
  type?: string
  folder?: string
  objectId?: number
  extension?: string
  referenced?: 'referenced' | 'unreferenced'
  page?: number
  limit?: number
  sort?: string
  order?: 'asc' | 'desc'
}

export type HealthStatus = 'ok' | 'warning' | 'critical'

export interface HealthCheckItem {
  name: string
  status: HealthStatus
  message: string
  details?: Record<string, unknown>
}

export interface HealthReport {
  status: HealthStatus
  checks: HealthCheckItem[]
}

export interface RuleOverlapItem {
  ruleA: string
  ruleB: string
  class: string
  sharedFields: string[]
  higherPriority: string | null
  samePriority: boolean
}

export interface RuleOverlapResponse {
  overlaps: RuleOverlapItem[]
}

export interface RulesExportArtifact {
  format_version: number
  rules: Record<string, Record<string, unknown>>
}

export interface RuleSetDiff {
  added: Record<string, Record<string, unknown>>
  removed: Record<string, Record<string, unknown>>
  changed: Record<string, { current: Record<string, unknown>; imported: Record<string, unknown> }>
  unchanged: string[]
  hasChanges: boolean
}

interface ReplaySelector {
  since?: string
  rule?: string
  class?: string
  async?: boolean
  limit?: number
}

export interface ReplayPreviewParams extends ReplaySelector {
  dryRun: true
}

export interface ReplayApplyParams extends ReplaySelector {
  dryRun: false
  planToken: string
}

export type ReplayParams = ReplayPreviewParams | ReplayApplyParams

interface ReviewedSelectionResponse {
  dryRun: boolean
  planToken: string | null
  runId: string | null
  statusUrl: string | null
  runStatus?: OperationRunStatus
  objectCount: number
  operations?: MoveOperation[]
  organized: number
  dispatched: number
  skipped: number
  failed: number
}

export interface ReplaySummary extends ReviewedSelectionResponse {
  candidates: number
}

interface ReorganizeSelector {
  folder: string
  limit?: number
  async?: boolean
}

export interface ReorganizePreviewRequest extends ReorganizeSelector {
  dryRun: true
}

export interface ReorganizeApplyRequest extends ReorganizeSelector {
  dryRun: false
  planToken: string
}

export type ReorganizeRequest = ReorganizePreviewRequest | ReorganizeApplyRequest

export interface ReorganizeResponse extends ReviewedSelectionResponse {
  assetsScanned: number
  ownerObjects: number
  truncated: boolean
}

// Shared asset summary (filename/path/size) used to enrich id-only listings.
export interface AssetSummary {
  id: number
  filename: string
  fullPath: string
  fileSize: number
  type: string
}

// Duplicates
export interface DuplicateGroup {
  checksum: string
  fileSize: number
  count: number
  assetIds: number[]
  representative: AssetSummary | null
}

export interface DuplicatesResponse {
  items: DuplicateGroup[]
  // null for a scoped/admin actor: the group list is an authorized scan-and-fill cursor, so the coarse
  // total is withheld and pagination is driven by hasMore. Exact only for the System actor.
  total: number | null
  page: number
  limit: number
  hasMore: boolean
  truncated: boolean
}

export interface DuplicateFilters {
  minCopies?: number
  type?: string
}

export interface MergeStrategies {
  strategies: string[]
  default: string
}

export type DispositionOutcome = 'quarantined' | 'deleted' | 'left_referenced' | 'left_error' | 'blocked' | 'skipped'

export interface MergeDisposition {
  copyId: number
  outcome: DispositionOutcome
  reason: string
}

export interface MergeResult {
  checksum: string
  canonicalId: number
  dryRun: boolean
  planToken: string | null
  runId: string | null
  status: OperationRunStatus | null
  statusUrl: string | null
  dispositions: MergeDisposition[]
}

// Integrity
export interface BrokenAssetItem {
  id: number
  path: string
  checker: string
  reason: string | null
}

export interface BrokenAssetsResponse {
  items: BrokenAssetItem[]
  scanned: number
  broken: number
  page: number
  limit: number
  hasNext: boolean
}

export interface BrokenAssetFilters {
  folder?: string
  type?: string
  extension?: string
}

export interface HealResultItem {
  assetId: number
  outcome: string
  toVersion: number | null
  checker: string
  reason: string | null
  observerWarnings: string[]
}

export interface HealResponse {
  dryRun: boolean
  planToken: string | null
  results: HealResultItem[]
}

export type UndoHealReason =
  | 'already_undone'
  | 'superseded'
  | 'asset_busy'
  | 'asset_not_found'
  | 'not_permitted'
  | 'asset_locked'
  | 'excluded_folder'
  | 'no_reversible_heal'
  | 'version_missing'
  | 'asset_changed'
  | 'restore_failed'
  | 'log_update_failed'

export interface HealHistoryItem {
  id: number
  assetId: number
  path: string
  fromVersion: number
  toVersion: number | null
  checker: string
  status: 'healed' | 'undone'
  createdAt: string
  eligible: boolean
  eligibilityReason: UndoHealReason | null
  reason: string | null
}

export interface HealHistoryResponse {
  items: HealHistoryItem[]
  total: number | null
  page: number
  pages: number | null
  hasMore: boolean
  truncated: boolean
}

// Quarantine
export interface QuarantineItem {
  asset_id: number
  original_path: string
  quarantined_at: string
  path: string
  filename: string
  type: string
  mimetype: string | null
}

export interface QuarantineResponse {
  items: QuarantineItem[]
  total: number | null
  page: number
  pages: number | null
  hasMore: boolean
  truncated: boolean
}

export interface QuarantineFilters {
  type?: string
  before?: string
  after?: string
}

// Storage trends
export interface StorageTrendPoint {
  capturedAt: string
  count: number
  size: number
  unknownSizeCount: number
}

export interface StorageTrendResponse {
  type: string | null
  items: StorageTrendPoint[]
}

// Empty folders
export interface EmptyFolderItem {
  id: number
  path: string
}

export interface EmptyFoldersResponse {
  items: EmptyFolderItem[]
  page: number
  limit: number
  hasMore: boolean
}

export interface EmptyFolderDeleteResult {
  deleted: number
  eligible?: number
  skipped: number
  failed: number
  errors: Record<number | string, string>
  dryRun: boolean
  planToken: string | null
}

// Location drift
export interface DriftItem {
  assetId: number
  currentPath: string
  expectedPath: string
  ruleName: string
  eligibility: 'no_known_block' | 'blocked' | 'runtime_check_required'
  reason: string | null
}

export interface DriftResponse {
  items: DriftItem[]
  objectsScanned: number
  page: number
  limit: number
  truncated: boolean
}
