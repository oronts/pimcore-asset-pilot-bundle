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
  objectClass?: string
}

export interface OrganizeRequest {
  objectId: number
  dryRun?: boolean
  async?: boolean
}

export interface BulkOrganizeRequest {
  className?: string
  objectIds?: number[]
  async?: boolean
  batchSize?: number
}

export interface OrganizeResponse {
  dryRun?: boolean
  operations?: MoveOperation[]
  results?: OperationResult[]
  message?: string
  objectCount?: number
  batchCount?: number
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
  created_at: string
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
  file_size: number
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
  total: number
  page: number
  pages: number
}

export interface PaginatedAssetResponse {
  items: AssetItem[]
  total: number
  page: number
  pages: number
}

export interface UnusedAssetStats {
  totalCount: number
  totalSize: number
  totalSizeFormatted: string
  byType: Array<{ type: string; count: number; total_size: number }>
}

export interface BulkActionResult {
  deleted?: number
  moved?: number
  tagged?: number
  updated?: number
  failed: number
  errors: Record<number | string, string>
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
  parentId: number
  path: string
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

export interface ReplayParams {
  since?: string
  rule?: string
  class?: string
  async?: boolean
  limit?: number
}

export interface ReplaySummary {
  candidates: number
  organized: number
  dispatched: number
  skipped: number
  failed: number
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
  total: number
  page: number
  limit: number
}

export interface DuplicateFilters {
  minCopies?: number
  type?: string
}

export interface MergeStrategies {
  strategies: string[]
  default: string
}

export interface MergeDisposition {
  copyId: number
  outcome: string
  reason: string
}

export interface MergeResult {
  checksum: string
  canonicalId: number
  dryRun: boolean
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
}

export interface HealResultItem {
  assetId: number
  outcome: string
  toVersion: number | null
  checker: string
  reason: string | null
}

export interface HealResponse {
  dryRun: boolean
  results: HealResultItem[]
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
  total: number
  page: number
  pages: number
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
}

export interface EmptyFolderDeleteResult {
  deleted: number
  skipped: number
  failed: number
  errors: Record<number | string, string>
}

// Location drift
export interface DriftItem {
  assetId: number
  currentPath: string
  expectedPath: string
  ruleName: string
}

export interface DriftResponse {
  items: DriftItem[]
  objectsScanned: number
  page: number
  limit: number
}
