import type {
  DashboardData,
  RuleData,
  RuleDetail,
  ClassStat,
  OrganizeRequest,
  OrganizeResponse,
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
  BulkActionResult,
  TagItem,
  AssetSearchFilters,
  HealthReport,
  RuleOverlapResponse,
  RulesExportArtifact,
  RuleSetDiff,
  ReplayParams,
  ReplaySummary,
  DuplicatesResponse,
  DuplicateFilters,
  MergeStrategies,
  MergeResult,
  BrokenAssetsResponse,
  HealResponse,
  QuarantineResponse,
  QuarantineFilters,
  StorageTrendResponse,
  EmptyFoldersResponse,
  EmptyFolderDeleteResult,
  DriftResponse,
} from '../types'
import { getPrefix } from '@pimcore/studio-ui-bundle/api'

const BASE_URL = `${getPrefix()}/asset-pilot`

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(message)
    this.name = 'ApiError'
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
    throw new ApiError(body.error ?? `Request failed (${response.status})`, response.status)
  }

  return response.json() as Promise<T>
}

function buildQuery(params: Record<string, string | number | undefined>): string {
  const entries = Object.entries(params).filter(([, v]) => v !== undefined && v !== '')
  if (entries.length === 0) return ''
  return '?' + entries.map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`).join('&')
}

export const assetPilotApi = {
  // Dashboard
  getDashboard: () => request<DashboardData>('/dashboard'),
  getClassStats: () => request<ClassStat[]>('/dashboard/class-stats'),

  // Health
  getHealth: () => request<HealthReport>('/health'),

  // Rules
  getRules: () => request<RuleData[]>('/rules'),
  getRuleDetail: (name: string) => request<RuleDetail>(`/rules/${encodeURIComponent(name)}`),
  getRuleOverlap: () => request<RuleOverlapResponse>('/rules/overlap'),
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
  previewRule: (name: string, objectId: number) =>
    request<unknown[]>(`/rules/${encodeURIComponent(name)}/preview?objectId=${objectId}`),
  applyRule: (name: string, objectId: number) =>
    request<OrganizeResponse>(`/rules/${encodeURIComponent(name)}/apply`, {
      method: 'POST',
      body: JSON.stringify({ objectId }),
    }),

  // Operations
  organize: (data: OrganizeRequest) =>
    request<OrganizeResponse>('/organize', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  organizeBulk: (data: BulkOrganizeRequest) =>
    request<OrganizeResponse>('/organize/bulk', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  organizeBulkPreview: (className: string, page = 1, limit = 50) =>
    request<BulkPreviewResponse>('/operations/bulk-preview', {
      method: 'POST',
      body: JSON.stringify({ className, page, limit }),
    }),
  getStatus: () => request<{ stats: Record<string, number | Record<string, number>>; recentOperations: AuditEntry[] }>('/operations/status'),
  replayFailures: (params: ReplayParams = {}) =>
    request<ReplaySummary>('/operations/replay', {
      method: 'POST',
      body: JSON.stringify(params),
    }),

  // Audit
  getAudit: (filters: AuditFilters = {}) =>
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
    ),
  revertOperation: (id: number) =>
    request<{ message: string; newPath: string }>(`/audit/${id}/revert`, { method: 'POST' }),
  exportAudit: (filters: AuditFilters = {}) => {
    const query = buildQuery({
      class: filters.class,
      status: filters.status,
      ruleName: filters.ruleName,
    })
    window.open(`${BASE_URL}/audit/export${query}`, '_blank')
  },

  // Unused Assets
  getUnusedAssets: (filters: UnusedAssetFilters = {}) =>
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
    ),
  getUnusedStats: () => request<UnusedAssetStats>('/unused-assets/stats'),
  bulkDeleteAssets: (assetIds: number[]) =>
    request<BulkActionResult>('/unused-assets/bulk-delete', {
      method: 'POST',
      body: JSON.stringify({ assetIds }),
    }),
  bulkMoveAssets: (assetIds: number[], targetFolder: string) =>
    request<BulkActionResult>('/unused-assets/bulk-move', {
      method: 'POST',
      body: JSON.stringify({ assetIds, targetFolder }),
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
    window.open(`${BASE_URL}/unused-assets/export${query}`, '_blank')
  },

  // Asset Management
  searchAssets: (filters: AssetSearchFilters = {}) =>
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
    ),
  assetImagePreviewUrl: (id: number): string => `${getPrefix()}/assets/${id}/image/stream/preview`,
  getAvailableTags: () => request<TagItem[]>('/assets/tags'),
  bulkTagAssets: (assetIds: number[], tagIds: number[], replace = false) =>
    request<BulkActionResult>('/assets/bulk-tag', {
      method: 'POST',
      body: JSON.stringify({ assetIds, tagIds, replace }),
    }),
  bulkSetProperty: (assetIds: number[], name: string, type: string, data: string | boolean) =>
    request<BulkActionResult>('/assets/bulk-property', {
      method: 'POST',
      body: JSON.stringify({ assetIds, name, type, data }),
    }),

  // Explain
  explainOrganize: (objectId: number) =>
    request<ExplainResponse>('/organize/explain', {
      method: 'POST',
      body: JSON.stringify({ objectId }),
    }),

  // Duplicates
  getDuplicates: (page = 1, limit = 50, filters: DuplicateFilters = {}) =>
    request<DuplicatesResponse>(`/duplicates${buildQuery({ page, limit, minCopies: filters.minCopies, type: filters.type })}`),
  getMergeStrategies: () => request<MergeStrategies>('/duplicates/strategies'),
  mergeDuplicates: (checksum: string, canonicalId?: number, strategy?: string, dryRun = false) =>
    request<MergeResult>('/duplicates/merge', {
      method: 'POST',
      body: JSON.stringify({ checksum, canonicalId, strategy, dryRun }),
    }),
  exportDuplicates: (filters: DuplicateFilters = {}): void => {
    window.open(`${BASE_URL}/duplicates/export${buildQuery({ minCopies: filters.minCopies, type: filters.type })}`, '_blank')
  },

  // Integrity
  getBrokenAssets: (page = 1, limit = 25, filters: { folder?: string; type?: string; extension?: string } = {}) =>
    request<BrokenAssetsResponse>(`/integrity${buildQuery({ page, limit, folder: filters.folder, type: filters.type, extension: filters.extension })}`),
  healAssets: (ids: number[], dryRun = false) =>
    request<HealResponse>('/integrity/heal', { method: 'POST', body: JSON.stringify({ ids, dryRun }) }),
  undoHeal: (assetId: number) =>
    request<{ assetId: number; undone: boolean }>('/integrity/undo', { method: 'POST', body: JSON.stringify({ assetId }) }),

  // Quarantine
  getQuarantine: (page = 1, limit = 50, filters: QuarantineFilters = {}) =>
    request<QuarantineResponse>(`/quarantine${buildQuery({ page, limit, type: filters.type, before: filters.before, after: filters.after })}`),
  restoreQuarantine: (assetId: number) =>
    request<{ message: string; assetId: number }>(`/quarantine/${assetId}/restore`, { method: 'POST' }),
  exportQuarantine: (filters: QuarantineFilters = {}): void => {
    window.open(`${BASE_URL}/quarantine/export${buildQuery({ type: filters.type, before: filters.before, after: filters.after })}`, '_blank')
  },

  // Storage trends
  getStorageTrends: (type?: string, limit = 90) =>
    request<StorageTrendResponse>(`/storage/trends${buildQuery({ type, limit })}`),

  // Empty folders
  getEmptyFolders: (page = 1, limit = 50) =>
    request<EmptyFoldersResponse>(`/folders/empty${buildQuery({ page, limit })}`),
  deleteEmptyFolders: (ids: number[]) =>
    request<EmptyFolderDeleteResult>('/folders/empty/delete', { method: 'POST', body: JSON.stringify({ ids }) }),

  // Location drift
  getDrift: (className: string, page = 1, limit = 50) =>
    request<DriftResponse>(`/rules/drift${buildQuery({ class: className, page, limit })}`),

  // Lock / Unlock
  lockAsset: (id: number) =>
    request<{ message: string; assetId: number }>(`/assets/${id}/lock`, { method: 'POST' }),
  unlockAsset: (id: number) =>
    request<{ message: string; assetId: number }>(`/assets/${id}/lock`, { method: 'DELETE' }),

  // Download selected assets jointly as a zip (the content-manager "cart").
  downloadZip: async (assetIds: number[], options: { strategy?: string; thumbnail?: string } = {}): Promise<void> => {
    const response = await fetch(`${BASE_URL}/assets/download-zip`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ assetIds, ...options }),
    })

    if (!response.ok) {
      const body = (await response.json().catch(() => ({}))) as { error?: string }
      throw new ApiError(body.error ?? `Download failed (${response.status})`, response.status)
    }

    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'assets.zip'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
  },
}
