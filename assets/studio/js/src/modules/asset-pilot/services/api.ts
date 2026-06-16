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
  AuditFilters,
  PreviewResponse,
  ExplainResponse,
  PaginatedUnusedResponse,
  UnusedAssetFilters,
  UnusedAssetStats,
  BulkActionResult,
  TagItem,
  AssetSearchFilters,
} from '../types'

const BASE_URL = '/pimcore-studio/api/asset-pilot'

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
    headers: { 'Content-Type': 'application/json', ...options?.headers },
    ...options,
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

  // Rules
  getRules: () => request<RuleData[]>('/rules'),
  getRuleDetail: (name: string) => request<RuleDetail>(`/rules/${encodeURIComponent(name)}`),
  previewRule: (name: string, objectId: number) =>
    request<unknown[]>(`/rules/${encodeURIComponent(name)}/preview?objectId=${objectId}`),

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
  previewOrganize: (objectId: number) =>
    request<PreviewResponse>('/organize/preview', {
      method: 'POST',
      body: JSON.stringify({ objectId }),
    }),
  organizeBulkPreview: (className: string, page = 1, limit = 50) =>
    request<BulkPreviewResponse>('/operations/bulk-preview', {
      method: 'POST',
      body: JSON.stringify({ className, page, limit }),
    }),
  getStatus: () => request<{ stats: Record<string, number>; recentOperations: unknown[] }>('/operations/status'),

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

  // Asset Management
  searchAssets: (filters: AssetSearchFilters = {}) =>
    request<PaginatedUnusedResponse>(
      `/assets/search${buildQuery({
        q: filters.q,
        type: filters.type,
        folder: filters.folder,
        objectId: filters.objectId,
        page: filters.page,
        limit: filters.limit,
        sort: filters.sort,
        order: filters.order,
      })}`,
    ),
  getAssetsByObject: (objectId: number, page = 1, limit = 50, type?: string) =>
    request<PaginatedUnusedResponse>(
      `/assets/by-object/${objectId}${buildQuery({ page, limit, type })}`,
    ),
  getAssetsByRule: (ruleName: string, page = 1, limit = 50, since?: string, className?: string) =>
    request<PaginatedUnusedResponse>(
      `/audit/by-rule/${encodeURIComponent(ruleName)}/assets${buildQuery({ page, limit, since, class: className })}`,
    ),
  getAvailableTags: () => request<TagItem[]>('/assets/tags'),
  getAssetTags: (assetId: number) => request<TagItem[]>(`/assets/${assetId}/tags`),
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

  // Permissions
  getPermissions: () =>
    request<{ view: boolean; operate: boolean; admin: boolean }>('/permissions'),

  // Lock / Unlock
  lockAsset: (id: number) =>
    request<{ message: string; assetId: number }>(`/assets/${id}/lock`, { method: 'POST' }),
  unlockAsset: (id: number) =>
    request<{ message: string; assetId: number }>(`/assets/${id}/lock`, { method: 'DELETE' }),
}
