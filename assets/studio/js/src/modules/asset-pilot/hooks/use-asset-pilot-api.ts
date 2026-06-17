import { useCallback, useEffect, useRef, useState } from 'react'
import { assetPilotApi } from '../services/api'
import type { DashboardData, RuleData, RuleDetail, PaginatedAuditResponse, AuditFilters, ClassStat, PaginatedUnusedResponse, PaginatedAssetResponse, UnusedAssetFilters, UnusedAssetStats, TagItem, AssetSearchFilters } from '../types'

interface AsyncState<T> {
  data: T | null
  loading: boolean
  error: string | null
  refetch: () => void
}

function useAsyncData<T>(fetcher: () => Promise<T>, deps: unknown[] = []): AsyncState<T> {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const requestId = useRef(0)

  const fetch = useCallback(() => {
    const id = ++requestId.current
    setLoading(true)
    setError(null)
    fetcher()
      .then(d => { if (id === requestId.current) { setData(d); setError(null) } })
      .catch((e: Error) => { if (id === requestId.current) setError(e.message) })
      .finally(() => { if (id === requestId.current) setLoading(false) })
  }, deps)

  useEffect(() => {
    fetch()
    return () => { requestId.current++ }
  }, [fetch])

  return { data, loading, error, refetch: fetch }
}

export function useDashboard(): AsyncState<DashboardData> {
  return useAsyncData(() => assetPilotApi.getDashboard())
}

export function useRules(): AsyncState<RuleData[]> {
  return useAsyncData(() => assetPilotApi.getRules())
}

export function useRuleDetail(name: string | null): AsyncState<RuleDetail> {
  return useAsyncData(
    () => name != null ? assetPilotApi.getRuleDetail(name) : Promise.reject(new Error('No rule selected')),
    [name],
  )
}

export function useAudit(filters: AuditFilters): AsyncState<PaginatedAuditResponse> {
  return useAsyncData(
    () => assetPilotApi.getAudit(filters),
    [filters.page, filters.limit, filters.class, filters.status, filters.ruleName, filters.sort, filters.order],
  )
}

export function useClassStats(): AsyncState<ClassStat[]> {
  return useAsyncData(() => assetPilotApi.getClassStats())
}

export function useUnusedAssets(filters: UnusedAssetFilters): AsyncState<PaginatedUnusedResponse> {
  return useAsyncData(
    () => assetPilotApi.getUnusedAssets(filters),
    [filters.page, filters.limit, filters.type, filters.extension, filters.before, filters.after, filters.folder, filters.confidence, filters.sort, filters.order],
  )
}

export function useUnusedStats(): AsyncState<UnusedAssetStats> {
  return useAsyncData(() => assetPilotApi.getUnusedStats())
}

export function useAssetSearch(filters: AssetSearchFilters): AsyncState<PaginatedAssetResponse> {
  return useAsyncData(
    () => assetPilotApi.searchAssets(filters),
    [filters.page, filters.limit, filters.q, filters.type, filters.folder, filters.objectId, filters.sort, filters.order],
  )
}

export function useTags(): AsyncState<TagItem[]> {
  return useAsyncData(() => assetPilotApi.getAvailableTags())
}
