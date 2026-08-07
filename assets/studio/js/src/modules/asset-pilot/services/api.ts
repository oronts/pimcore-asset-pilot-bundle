import type {
  DashboardData,
  RuleData,
  RuleDetail,
  ClassStat,
  OrganizeRequest,
  OrganizeResponse,
  RulePreviewResponse,
  BulkOrganizeRequest,
  BulkPreviewResponse,
  PaginatedAuditResponse,
  AuditEntry,
  AuditFilters,
  ExplainResponse,
  PaginatedUnusedResponse,
  PaginatedAssetResponse,
  UnusedAssetFilters,
  UnusedAssetStats,
  PlannedBulkActionResult,
  TagSearchResponse,
  AssetSearchFilters,
  HealthReport,
  RuleOverlapResponse,
  RulesExportArtifact,
  RuleSetDiff,
  ReplayParams,
  ReplaySummary,
  ReorganizeRequest,
  ReorganizeResponse,
  DuplicatesResponse,
  DuplicateFilters,
  MergeStrategies,
  MergeResult,
  BrokenAssetsResponse,
  BrokenAssetFilters,
  HealResponse,
  HealHistoryResponse,
  QuarantineResponse,
  QuarantineFilters,
  StorageTrendResponse,
  EmptyFoldersResponse,
  EmptyFolderDeleteResult,
  DriftResponse,
  OperationRun,
  OperationRunListResponse,
  SimulateResponse,
  OperationRunMutationResponse,
  OperationRunRetryResponse,
  OperationRecoveryResponse,
  DeliveryRetryResponse,
} from '../types'
import { getPrefix } from '@pimcore/studio-ui-bundle/api'
import i18n from 'i18next'

const BASE_URL = `${getPrefix()}/asset-pilot`

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    // Parsed error body, so structured fields survive instead of being flattened to a message.
    public readonly details?: unknown,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

// A recoverable duplicate-merge 409: the run can be resumed by id without a fresh plan.
export interface MergeConflictRecovery {
  runId: string
  rootRunId?: string
  statusUrl?: string
}

export function mergeConflictRecovery(details: unknown): MergeConflictRecovery | null {
  if (typeof details !== 'object' || details === null) return null
  const body = details as Record<string, unknown>
  if (typeof body.runId !== 'string' || body.runId === '') return null

  return {
    runId: body.runId,
    rootRunId: typeof body.rootRunId === 'string' ? body.rootRunId : undefined,
    statusUrl: typeof body.statusUrl === 'string' ? body.statusUrl : undefined,
  }
}

async function request<T>(path: string, options?: RequestInit): Promise<T> {
  const response = await fetch(`${BASE_URL}${path}`, {
    ...options,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', ...options?.headers },
  })

  if (!response.ok) {
    const body = await response.json().catch(() => ({}))
    throw new ApiError(body.error ?? `Request failed (${response.status})`, response.status, body)
  }

  return response.json() as Promise<T>
}

function buildQuery(params: Record<string, string | number | undefined>): string {
  const entries = Object.entries(params).filter(([, v]) => v !== undefined && v !== '')
  if (entries.length === 0) return ''
  return '?' + entries.map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`).join('&')
}

function openExport(path: string): void {
  const opened = window.open(`${BASE_URL}${path}`, '_blank', 'noopener,noreferrer')
  if (opened != null) opened.opener = null
}

export const assetPilotApi = {
  // Dashboard
  getDashboard: (signal?: AbortSignal) => request<DashboardData>('/dashboard', { signal }),
  getClassStats: (signal?: AbortSignal) => request<ClassStat[]>('/dashboard/class-stats', { signal }),

  // Health
  getHealth: (signal?: AbortSignal) => request<HealthReport>('/health', { signal }),

  // Rules
  getRules: (signal?: AbortSignal) => request<RuleData[]>('/rules', { signal }),
  getRuleDetail: (name: string, signal?: AbortSignal) => request<RuleDetail>(`/rules/${encodeURIComponent(name)}`, { signal }),
  getRuleOverlap: (signal?: AbortSignal) => request<RuleOverlapResponse>('/rules/overlap', { signal }),
  diffRules: (artifact: unknown) =>
    request<RuleSetDiff>('/rules/diff', { method: 'POST', body: JSON.stringify(artifact) }),
  exportRules: async (): Promise<void> => {
    const artifact = await request<RulesExportArtifact>('/rules/export')
    const blob = new Blob([JSON.stringify(artifact, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'asset-pilot-rules.json'
    link.click()
    URL.revokeObjectURL(url)
  },
  previewRule: (name: string, objectId: number, signal?: AbortSignal) =>
    request<RulePreviewResponse>(`/rules/${encodeURIComponent(name)}/preview?objectId=${objectId}`, { signal }),
  applyRule: (name: string, objectId: number, planToken: string, signal?: AbortSignal) =>
    request<OrganizeResponse>(`/rules/${encodeURIComponent(name)}/apply`, {
      method: 'POST',
      body: JSON.stringify({ objectId, planToken }),
      signal,
    }),

  // Operations
  organize: (data: OrganizeRequest, signal?: AbortSignal) =>
    request<OrganizeResponse>('/organize', {
      method: 'POST',
      body: JSON.stringify(data),
      signal,
    }),
  organizeBulk: (data: BulkOrganizeRequest, signal?: AbortSignal) =>
    request<OrganizeResponse>('/organize/bulk', {
      method: 'POST',
      body: JSON.stringify(data),
      signal,
    }),
  organizeBulkPreview: (className: string, page = 1, limit = 50, signal?: AbortSignal) =>
    request<BulkPreviewResponse>('/operations/bulk-preview', {
      method: 'POST',
      body: JSON.stringify({ className, page, limit }),
      signal,
    }),
  getStatus: (signal?: AbortSignal) => request<{ stats: Record<string, number | Record<string, number>>; recentOperations: AuditEntry[] }>('/operations/status', { signal }),
  replayFailures: (params: ReplayParams, signal?: AbortSignal) =>
    request<ReplaySummary>('/operations/replay', {
      method: 'POST',
      body: JSON.stringify(params),
      signal,
    }),
  reorganize: (params: ReorganizeRequest, signal?: AbortSignal) =>
    request<ReorganizeResponse>('/operations/reorganize', {
      method: 'POST',
      body: JSON.stringify(params),
      signal,
    }),
  getOperationRuns: (limit = 20, signal?: AbortSignal) =>
    request<OperationRunListResponse>(`/operations/runs${buildQuery({ limit })}`, { signal }),
  getSimulations: (limit = 20, signal?: AbortSignal) =>
    request<OperationRunListResponse>(`/operations/runs${buildQuery({ limit, kind: 'simulation' })}`, { signal }),
  simulate: (objectId: number, signal?: AbortSignal) =>
    request<SimulateResponse>('/operations/simulate', {
      method: 'POST',
      body: JSON.stringify({ objectId }),
      signal,
    }),
  getOperationRun: (id: string, signal?: AbortSignal) =>
    request<OperationRun>(`/operations/runs/${encodeURIComponent(id)}`, { signal }),
  cancelOperationRun: (id: string) =>
    request<OperationRunMutationResponse>(`/operations/runs/${encodeURIComponent(id)}/cancel`, { method: 'POST' }),
  retryOperationRun: (id: string) =>
    request<OperationRunRetryResponse>(`/operations/runs/${encodeURIComponent(id)}/retry`, { method: 'POST' }),
  previewOperationRecovery: (limit: number, signal?: AbortSignal) =>
    request<OperationRecoveryResponse>('/operations/recovery', {
      method: 'POST',
      body: JSON.stringify({ limit, apply: false }),
      signal,
    }),
  applyOperationRecovery: (limit: number, planToken: string, signal?: AbortSignal) =>
    request<OperationRecoveryResponse>('/operations/recovery', {
      method: 'POST',
      body: JSON.stringify({ limit, apply: true, planToken }),
      signal,
    }),
  previewDeliveryRetry: (limit: number, signal?: AbortSignal) =>
    request<DeliveryRetryResponse>('/operations/deliveries/retry', {
      method: 'POST',
      body: JSON.stringify({ limit, apply: false }),
      signal,
    }),
  applyDeliveryRetry: (limit: number, planToken: string, signal?: AbortSignal) =>
    request<DeliveryRetryResponse>('/operations/deliveries/retry', {
      method: 'POST',
      body: JSON.stringify({ limit, apply: true, planToken }),
      signal,
    }),

  // Audit
  getAudit: (filters: AuditFilters = {}, signal?: AbortSignal) =>
    request<PaginatedAuditResponse>(
      `/audit${buildQuery({
        page: filters.page,
        limit: filters.limit,
        class: filters.class,
        status: filters.status,
        ruleName: filters.ruleName,
        sort: filters.sort,
        order: filters.order,
      })}`,
      { signal },
    ),
  revertOperation: (id: number) =>
    request<{ message: string; newPath: string }>(`/audit/${id}/revert`, { method: 'POST' }),
  exportAudit: (filters: AuditFilters = {}) => {
    const query = buildQuery({
      class: filters.class,
      status: filters.status,
      ruleName: filters.ruleName,
    })
    openExport(`/audit/export${query}`)
  },

  // Unused Assets
  getUnusedAssets: (filters: UnusedAssetFilters = {}, signal?: AbortSignal) =>
    request<PaginatedUnusedResponse>(
      `/unused-assets${buildQuery({
        page: filters.page,
        limit: filters.limit,
        type: filters.type,
        extension: filters.extension,
        before: filters.before,
        after: filters.after,
        folder: filters.folder,
        confidence: filters.confidence,
        sort: filters.sort,
        order: filters.order,
      })}`,
      { signal },
    ),
  getUnusedStats: (signal?: AbortSignal) => request<UnusedAssetStats>('/unused-assets/stats', { signal }),
  previewBulkDeleteAssets: (assetIds: number[], signal?: AbortSignal) =>
    request<PlannedBulkActionResult>('/unused-assets/bulk-delete', {
      method: 'POST',
      body: JSON.stringify({ assetIds, dryRun: true }),
      signal,
    }),
  applyBulkDeleteAssets: (assetIds: number[], planToken: string, signal?: AbortSignal) =>
    request<PlannedBulkActionResult>('/unused-assets/bulk-delete', {
      method: 'POST',
      body: JSON.stringify({ assetIds, dryRun: false, planToken }),
      signal,
    }),
  previewBulkMoveAssets: (assetIds: number[], targetFolder: string, signal?: AbortSignal) =>
    request<PlannedBulkActionResult>('/unused-assets/bulk-move', {
      method: 'POST',
      body: JSON.stringify({ assetIds, targetFolder, dryRun: true }),
      signal,
    }),
  applyBulkMoveAssets: (assetIds: number[], targetFolder: string, planToken: string, signal?: AbortSignal) =>
    request<PlannedBulkActionResult>('/unused-assets/bulk-move', {
      method: 'POST',
      body: JSON.stringify({ assetIds, targetFolder, dryRun: false, planToken }),
      signal,
    }),
  previewBulkQuarantineAssets: (assetIds: number[], signal?: AbortSignal) =>
    request<PlannedBulkActionResult>('/unused-assets/bulk-quarantine', {
      method: 'POST',
      body: JSON.stringify({ assetIds, dryRun: true }),
      signal,
    }),
  applyBulkQuarantineAssets: (assetIds: number[], planToken: string, signal?: AbortSignal) =>
    request<PlannedBulkActionResult>('/unused-assets/bulk-quarantine', {
      method: 'POST',
      body: JSON.stringify({ assetIds, dryRun: false, planToken }),
      signal,
    }),
  exportUnused: (filters: UnusedAssetFilters = {}): void => {
    const query = buildQuery({
      type: filters.type,
      extension: filters.extension,
      before: filters.before,
      after: filters.after,
      folder: filters.folder,
      confidence: filters.confidence,
    })
    openExport(`/unused-assets/export${query}`)
  },

  // Asset Management
  searchAssets: (filters: AssetSearchFilters = {}, signal?: AbortSignal) =>
    request<PaginatedAssetResponse>(
      `/assets/search${buildQuery({
        q: filters.q,
        type: filters.type,
        folder: filters.folder,
        objectId: filters.objectId,
        extension: filters.extension,
        referenced: filters.referenced,
        page: filters.page,
        limit: filters.limit,
        sort: filters.sort,
        order: filters.order,
      })}`,
      { signal },
    ),
  assetImagePreviewUrl: (id: number): string => `${getPrefix()}/assets/${id}/image/stream/preview`,
  getAvailableTags: (page = 1, limit = 50, query?: string, signal?: AbortSignal) =>
    request<TagSearchResponse>(`/assets/tags${buildQuery({ page, limit, q: query })}`, { signal }),
  previewBulkTagAssets: (assetIds: number[], tagIds: number[], replace = false, signal?: AbortSignal) =>
    request<PlannedBulkActionResult>('/assets/bulk-tag', {
      method: 'POST',
      body: JSON.stringify({ assetIds, tagIds, replace, dryRun: true }),
      signal,
    }),
  applyBulkTagAssets: (assetIds: number[], tagIds: number[], replace: boolean, planToken: string, signal?: AbortSignal) =>
    request<PlannedBulkActionResult>('/assets/bulk-tag', {
      method: 'POST',
      body: JSON.stringify({ assetIds, tagIds, replace, dryRun: false, planToken }),
      signal,
    }),
  previewBulkSetProperty: (assetIds: number[], name: string, type: string, data: string | boolean, signal?: AbortSignal) =>
    request<PlannedBulkActionResult>('/assets/bulk-property', {
      method: 'POST',
      body: JSON.stringify({ assetIds, name, type, data, dryRun: true }),
      signal,
    }),
  applyBulkSetProperty: (
    assetIds: number[],
    name: string,
    type: string,
    data: string | boolean,
    planToken: string,
    signal?: AbortSignal,
  ) =>
    request<PlannedBulkActionResult>('/assets/bulk-property', {
      method: 'POST',
      body: JSON.stringify({ assetIds, name, type, data, dryRun: false, planToken }),
      signal,
    }),

  // Explain
  explainOrganize: (objectId: number) =>
    request<ExplainResponse>('/organize/explain', {
      method: 'POST',
      body: JSON.stringify({ objectId }),
    }),

  // Duplicates
  getDuplicates: (page = 1, limit = 50, filters: DuplicateFilters = {}, signal?: AbortSignal) =>
    request<DuplicatesResponse>(`/duplicates${buildQuery({ page, limit, minCopies: filters.minCopies, type: filters.type })}`, { signal }),
  getMergeStrategies: (signal?: AbortSignal) => request<MergeStrategies>('/duplicates/strategies', { signal }),
  mergeDuplicates: (checksum: string, canonicalId?: number, strategy?: string, dryRun = false, planToken?: string, signal?: AbortSignal) =>
    request<MergeResult>('/duplicates/merge', {
      method: 'POST',
      body: JSON.stringify({ checksum, canonicalId, strategy, dryRun, planToken }),
      signal,
    }),
  resumeDuplicateMerge: (runId: string, signal?: AbortSignal) =>
    request<MergeResult>('/duplicates/merge', {
      method: 'POST',
      body: JSON.stringify({ runId }),
      signal,
    }),
  exportDuplicates: (filters: DuplicateFilters = {}): void => {
    openExport(`/duplicates/export${buildQuery({ minCopies: filters.minCopies, type: filters.type })}`)
  },

  // Integrity
  getBrokenAssets: (page = 1, limit = 25, filters: BrokenAssetFilters = {}, signal?: AbortSignal) =>
    request<BrokenAssetsResponse>(`/integrity${buildQuery({ page, limit, folder: filters.folder, type: filters.type, extension: filters.extension })}`, { signal }),
  previewHealAssets: (ids: number[], signal?: AbortSignal) =>
    request<HealResponse>('/integrity/heal', {
      method: 'POST',
      body: JSON.stringify({ ids, dryRun: true }),
      signal,
    }),
  applyHealAssets: (ids: number[], planToken: string, signal?: AbortSignal) =>
    request<HealResponse>('/integrity/heal', {
      method: 'POST',
      body: JSON.stringify({ ids, dryRun: false, planToken }),
      signal,
    }),
  getHealHistory: (page = 1, limit = 25, signal?: AbortSignal) =>
    request<HealHistoryResponse>(`/integrity/history${buildQuery({ page, limit })}`, { signal }),
  undoHeal: (assetId: number) =>
    request<{ assetId: number; undone: boolean }>('/integrity/undo', { method: 'POST', body: JSON.stringify({ assetId }) }),

  // Quarantine
  getQuarantine: (page = 1, limit = 50, filters: QuarantineFilters = {}, signal?: AbortSignal) =>
    request<QuarantineResponse>(`/quarantine${buildQuery({ page, limit, type: filters.type, before: filters.before, after: filters.after })}`, { signal }),
  restoreQuarantine: (assetId: number) =>
    request<{ message: string; assetId: number }>(`/quarantine/${assetId}/restore`, { method: 'POST' }),
  exportQuarantine: (filters: QuarantineFilters = {}): void => {
    openExport(`/quarantine/export${buildQuery({ type: filters.type, before: filters.before, after: filters.after })}`)
  },

  // Storage trends
  getStorageTrends: (type?: string, limit = 90, signal?: AbortSignal) =>
    request<StorageTrendResponse>(`/storage/trends${buildQuery({ type, limit })}`, { signal }),

  // Empty folders
  getEmptyFolders: (page = 1, limit = 50, signal?: AbortSignal) =>
    request<EmptyFoldersResponse>(`/folders/empty${buildQuery({ page, limit })}`, { signal }),
  previewDeleteEmptyFolders: (ids: number[], signal?: AbortSignal) =>
    request<EmptyFolderDeleteResult>('/folders/empty/delete', {
      method: 'POST',
      body: JSON.stringify({ ids, dryRun: true }),
      signal,
    }),
  applyDeleteEmptyFolders: (ids: number[], planToken: string, signal?: AbortSignal) =>
    request<EmptyFolderDeleteResult>('/folders/empty/delete', {
      method: 'POST',
      body: JSON.stringify({ ids, dryRun: false, planToken }),
      signal,
    }),

  // Location drift
  getDrift: (className: string, page = 1, limit = 50, signal?: AbortSignal) =>
    request<DriftResponse>(`/rules/drift${buildQuery({ class: className, page, limit })}`, { signal }),

  // Lock / Unlock
  lockAsset: (id: number) =>
    request<{ message: string; assetId: number }>(`/assets/${id}/lock`, { method: 'POST' }),
  unlockAsset: (id: number) =>
    request<{ message: string; assetId: number }>(`/assets/${id}/lock`, { method: 'DELETE' }),

  // Download selected assets jointly as a zip (the content-manager "cart").
  downloadZip: async (assetIds: number[], options: { strategy?: string; thumbnail?: string } = {}): Promise<void> => {
    const downloadWindow = window.open('about:blank', '_blank')
    if (downloadWindow == null) throw new ApiError(i18n.t('asset-pilot.management.zip-popup-blocked'), 0)
    downloadWindow.opener = null

    try {
      const { token } = await request<{ token: string }>('/assets/download-zip/prepare', {
        method: 'POST',
        body: JSON.stringify({ assetIds, ...options }),
      })
      downloadWindow.location.replace(`${BASE_URL}/assets/download-zip/${encodeURIComponent(token)}`)
    } catch (error) {
      downloadWindow.close()
      throw error
    }
  },
}
