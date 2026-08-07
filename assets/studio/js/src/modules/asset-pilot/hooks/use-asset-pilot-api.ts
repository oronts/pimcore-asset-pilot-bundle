import { useCallback, useEffect, useRef, useState } from 'react'
import { assetPilotApi } from '../services/api'
import type { DashboardData, RuleData, RuleDetail, PaginatedAuditResponse, AuditFilters, ClassStat, PaginatedUnusedResponse, PaginatedAssetResponse, UnusedAssetFilters, UnusedAssetStats, TagSearchResponse, AssetSearchFilters, HealthReport, RuleOverlapResponse, DuplicatesResponse, DuplicateFilters, MergeStrategies, BrokenAssetsResponse, BrokenAssetFilters, HealHistoryResponse, QuarantineResponse, QuarantineFilters, StorageTrendResponse, EmptyFoldersResponse, DriftResponse } from '../types'
import { useDebouncedValue } from './use-debounced-value'
import { useTranslation } from 'react-i18next'

interface AsyncState<T> {
  data: T | null
  loading: boolean
  error: string | null
  refetch: () => void
}

export function useAsyncData<T>(fetcher: (signal: AbortSignal) => Promise<T>): AsyncState<T> {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const requestId = useRef(0)
  const activeRequest = useRef<AbortController | null>(null)

  const cancelActiveRequest = useCallback((): void => {
    requestId.current++
    activeRequest.current?.abort()
  }, [])

  const fetch = useCallback(() => {
    cancelActiveRequest()
    const controller = new AbortController()
    activeRequest.current = controller
    const id = requestId.current
    setLoading(true)
    setError(null)
    fetcher(controller.signal)
      .then(d => { if (id === requestId.current) { setData(d); setError(null) } })
      .catch((e: Error) => {
        if (id === requestId.current && e.name !== 'AbortError') setError(e.message)
      })
      .finally(() => { if (id === requestId.current) setLoading(false) })
  }, [cancelActiveRequest, fetcher])

  useEffect(() => {
    fetch()
    return cancelActiveRequest
  }, [cancelActiveRequest, fetch])

  return { data, loading, error, refetch: fetch }
}

export function useDashboard(): AsyncState<DashboardData> {
  const fetcher = useCallback((signal: AbortSignal) => assetPilotApi.getDashboard(signal), [])

  return useAsyncData(fetcher)
}

export function useRules(): AsyncState<RuleData[]> {
  const fetcher = useCallback((signal: AbortSignal) => assetPilotApi.getRules(signal), [])

  return useAsyncData(fetcher)
}

export function useHealth(): AsyncState<HealthReport> {
  const fetcher = useCallback((signal: AbortSignal) => assetPilotApi.getHealth(signal), [])

  return useAsyncData(fetcher)
}

export function useRuleOverlap(): AsyncState<RuleOverlapResponse> {
  const fetcher = useCallback((signal: AbortSignal) => assetPilotApi.getRuleOverlap(signal), [])

  return useAsyncData(fetcher)
}

export function useRuleDetail(name: string | null): AsyncState<RuleDetail> {
  const { t } = useTranslation()
  const fetcher = useCallback(
    (signal: AbortSignal) => name != null ? assetPilotApi.getRuleDetail(name, signal) : Promise.reject(new Error(t('asset-pilot.errors.no-rule-selected'))),
    [name, t],
  )

  return useAsyncData(fetcher)
}

export function useAudit(filters: AuditFilters): AsyncState<PaginatedAuditResponse> {
  const { page, limit, class: className, status, ruleName, sort, order } = filters
  const debouncedClassName = useDebouncedValue(className)
  const debouncedRuleName = useDebouncedValue(ruleName)
  const fetcher = useCallback(
    (signal: AbortSignal) => assetPilotApi.getAudit({ page, limit, class: debouncedClassName, status, ruleName: debouncedRuleName, sort, order }, signal),
    [page, limit, debouncedClassName, status, debouncedRuleName, sort, order],
  )

  return useAsyncData(fetcher)
}

export function useClassStats(): AsyncState<ClassStat[]> {
  const fetcher = useCallback((signal: AbortSignal) => assetPilotApi.getClassStats(signal), [])

  return useAsyncData(fetcher)
}

export function useUnusedAssets(filters: UnusedAssetFilters): AsyncState<PaginatedUnusedResponse> {
  const { page, limit, type, extension, before, after, folder, confidence, sort, order } = filters
  const debouncedExtension = useDebouncedValue(extension)
  const debouncedFolder = useDebouncedValue(folder)
  const fetcher = useCallback(
    (signal: AbortSignal) => assetPilotApi.getUnusedAssets({ page, limit, type, extension: debouncedExtension, before, after, folder: debouncedFolder, confidence, sort, order }, signal),
    [page, limit, type, debouncedExtension, before, after, debouncedFolder, confidence, sort, order],
  )

  return useAsyncData(fetcher)
}

export function useUnusedStats(): AsyncState<UnusedAssetStats> {
  const fetcher = useCallback((signal: AbortSignal) => assetPilotApi.getUnusedStats(signal), [])

  return useAsyncData(fetcher)
}

export function useAssetSearch(filters: AssetSearchFilters): AsyncState<PaginatedAssetResponse> {
  const { page, limit, q, type, folder, objectId, extension, referenced, sort, order } = filters
  const debouncedFolder = useDebouncedValue(folder)
  const debouncedExtension = useDebouncedValue(extension)
  const fetcher = useCallback(
    (signal: AbortSignal) => assetPilotApi.searchAssets({ page, limit, q, type, folder: debouncedFolder, objectId, extension: debouncedExtension, referenced, sort, order }, signal),
    [page, limit, q, type, debouncedFolder, objectId, debouncedExtension, referenced, sort, order],
  )

  return useAsyncData(fetcher)
}

export function useTags(page: number, limit: number, query: string): AsyncState<TagSearchResponse> {
  const fetcher = useCallback(
    (signal: AbortSignal) => assetPilotApi.getAvailableTags(page, limit, query, signal),
    [page, limit, query],
  )

  return useAsyncData(fetcher)
}

export function useDuplicates(page: number, limit: number, filters: DuplicateFilters = {}): AsyncState<DuplicatesResponse> {
  const { minCopies, type } = filters
  const fetcher = useCallback(
    (signal: AbortSignal) => assetPilotApi.getDuplicates(page, limit, { minCopies, type }, signal),
    [page, limit, minCopies, type],
  )

  return useAsyncData(fetcher)
}

export function useMergeStrategies(): AsyncState<MergeStrategies> {
  const fetcher = useCallback((signal: AbortSignal) => assetPilotApi.getMergeStrategies(signal), [])

  return useAsyncData(fetcher)
}

export function useBrokenAssets(page: number, limit: number, filters: BrokenAssetFilters = {}): AsyncState<BrokenAssetsResponse> {
  const { folder, type, extension } = filters
  const fetcher = useCallback(
    (signal: AbortSignal) => assetPilotApi.getBrokenAssets(page, limit, { folder, type, extension }, signal),
    [page, limit, folder, type, extension],
  )

  return useAsyncData(fetcher)
}

export function useHealHistory(page: number, limit: number): AsyncState<HealHistoryResponse> {
  const fetcher = useCallback(
    (signal: AbortSignal) => assetPilotApi.getHealHistory(page, limit, signal),
    [page, limit],
  )

  return useAsyncData(fetcher)
}

export function useQuarantine(page: number, limit: number, filters: QuarantineFilters = {}): AsyncState<QuarantineResponse> {
  const { type, before, after } = filters
  const fetcher = useCallback(
    (signal: AbortSignal) => assetPilotApi.getQuarantine(page, limit, { type, before, after }, signal),
    [page, limit, type, before, after],
  )

  return useAsyncData(fetcher)
}

export function useStorageTrends(type: string | undefined, limit: number): AsyncState<StorageTrendResponse> {
  const fetcher = useCallback(
    (signal: AbortSignal) => assetPilotApi.getStorageTrends(type, limit, signal),
    [type, limit],
  )

  return useAsyncData(fetcher)
}

export function useEmptyFolders(page: number, limit: number): AsyncState<EmptyFoldersResponse> {
  const fetcher = useCallback(
    (signal: AbortSignal) => assetPilotApi.getEmptyFolders(page, limit, signal),
    [page, limit],
  )

  return useAsyncData(fetcher)
}

export function useDrift(className: string | null, page: number, limit = 50): AsyncState<DriftResponse> {
  const { t } = useTranslation()
  const fetcher = useCallback(
    (signal: AbortSignal) => className != null && className !== '' ? assetPilotApi.getDrift(className, page, limit, signal) : Promise.reject(new Error(t('asset-pilot.errors.no-class-selected'))),
    [className, page, limit, t],
  )

  return useAsyncData(fetcher)
}
