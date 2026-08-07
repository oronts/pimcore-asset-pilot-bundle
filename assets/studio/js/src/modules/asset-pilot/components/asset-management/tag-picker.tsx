import React, { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useTags } from '../../hooks/use-asset-pilot-api'
import { assetPilotApi } from '../../services/api'
import type { MutationFeedback, PlannedBulkActionResult } from '../../types'
import { Pagination } from '../shared/pagination'
import { MetadataPlanReview, metadataResultDetails } from './metadata-plan-review'
import { useReviewedMetadataPlan } from './use-reviewed-metadata-plan'

interface TagPickerProps {
  assetIds: number[]
  onDone: (result: MutationFeedback) => void
  onCancel: () => void
}

interface ReviewedTagPlan {
  assetIds: number[]
  assetSignature: string
  tagIds: number[]
  replace: boolean
  planToken: string
  result: PlannedBulkActionResult
}

export const TagPicker: React.FC<TagPickerProps> = ({ assetIds, onDone, onCancel }) => {
  const { t } = useTranslation()
  const [selectedTagIds, setSelectedTagIds] = useState<Set<number>>(new Set())
  const [replace, setReplace] = useState(false)
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const limit = 50
  const normalizedAssetIds = [...assetIds].sort((a, b) => a - b)
  const assetSignature = normalizedAssetIds.join(',')
  const plan = useReviewedMetadataPlan<ReviewedTagPlan>(assetSignature)
  const { status, reviewedPlan, error, notice } = plan
  const { data: response, loading: tagsLoading, error: tagsError, refetch: refetchTags } = useTags(page, limit, query)
  const tags = tagsLoading ? [] : (response?.items ?? [])

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setPage(1)
      setQuery(search.trim())
    }, 250)

    return () => window.clearTimeout(timeout)
  }, [search])

  const toggleTag = (id: number): void => {
    plan.invalidate()
    setSelectedTagIds(prev => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)

      return next
    })
  }

  const handlePreview = (): void => {
    if (selectedTagIds.size === 0) return

    const tagIds = [...selectedTagIds].sort((a, b) => a - b)
    void plan.review(
      signal => assetPilotApi.previewBulkTagAssets(normalizedAssetIds, tagIds, replace, signal),
      result => ({
        assetIds: normalizedAssetIds,
        assetSignature,
        tagIds,
        replace,
        planToken: result.planToken as string,
        result,
      }),
    )
  }

  const handleApply = (): void => {
    void plan.apply(
      (reviewed, signal) => assetPilotApi.applyBulkTagAssets(reviewed.assetIds, reviewed.tagIds, reviewed.replace, reviewed.planToken, signal),
      result => {
        const summary = t('asset-pilot.management.tag-result', {
          tagged: result.tagged ?? 0,
          failed: result.failed,
        })
        const details = metadataResultDetails(
          result,
          (id, reason) => t('asset-pilot.management.review-asset-error', { id, reason }),
        )
        onDone({
          severity: result.failed > 0 || (result.observerWarnings ?? []).length > 0 ? 'warning' : 'success',
          message: [summary, details].filter(part => part !== '').join(' '),
        })
      },
    )
  }

  return (
    <div style={{ padding: '8px 0' }}>
      <div style={{ fontSize: 'var(--ap-font-size)', fontWeight: 600, marginBottom: 6, color: 'var(--ap-color-text)' }}>
        {t('asset-pilot.management.tags-title')}
      </div>

      <input
        type="text"
        aria-label={t('asset-pilot.management.tags-search')}
        placeholder={t('asset-pilot.management.tags-search')}
        value={search}
        onChange={e => setSearch(e.target.value)}
        style={{ ...inputStyle, width: '100%', marginBottom: 6 }}
      />

      <div style={{ maxHeight: 160, overflowY: 'auto', border: '1px solid var(--ap-color-border-secondary)', borderRadius: 6, padding: 4 }}>
        {tagsLoading && <div style={{ padding: 8, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.common.loading')}</div>}
        {tagsError != null && (
          <div role="alert" style={{ padding: 8, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-error-text-active)' }}>
            {t('asset-pilot.common.error', { message: tagsError })} <button onClick={refetchTags}>{t('asset-pilot.common.retry')}</button>
          </div>
        )}
        {!tagsLoading && tagsError == null && tags.length === 0 && (
          <div style={{ padding: 8, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.management.tags-none')}</div>
        )}
        {tags.map(tag => (
          <label key={tag.id} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '3px 6px', cursor: 'pointer', fontSize: 'var(--ap-font-size)' }}>
            <input type="checkbox" checked={selectedTagIds.has(tag.id)} disabled={status != null} onChange={() => toggleTag(tag.id)} />
            <span>{tag.path || tag.name}</span>
          </label>
        ))}
      </div>

      <Pagination page={page} pages={response?.pages ?? 0} onPage={setPage} />

      <label style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 6, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', cursor: 'pointer' }}>
        <input
          type="checkbox"
          checked={replace}
          disabled={status != null}
          onChange={event => {
            plan.invalidate()
            setReplace(event.target.checked)
          }}
        />
        {t('asset-pilot.management.tags-replace')}
      </label>

      {error != null && <p role="alert" style={errorStyle}>{error}</p>}
      {notice != null && <p role="status" style={noticeStyle}>{notice}</p>}
      {reviewedPlan != null && (
        <MetadataPlanReview requested={reviewedPlan.assetIds.length} result={reviewedPlan.result} />
      )}

      <div style={{ display: 'flex', gap: 6, marginTop: 8 }}>
        <button
          onClick={handlePreview}
          disabled={status != null || selectedTagIds.size === 0}
          style={reviewBtnStyle}
        >
          {status === 'preview' ? t('asset-pilot.management.tags-reviewing') : t('asset-pilot.management.tags-review')}
        </button>
        {reviewedPlan != null && (
          <button
            onClick={handleApply}
            disabled={status != null}
            style={confirmBtnStyle}
          >
            {status === 'apply' ? t('asset-pilot.management.tags-applying') : t('asset-pilot.management.tags-apply')}
          </button>
        )}
        <button onClick={onCancel} disabled={status != null} style={cancelBtnStyle}>
          {t('asset-pilot.common.cancel')}
        </button>
      </div>
    </div>
  )
}

const inputStyle: React.CSSProperties = { padding: '4px 8px', border: '1px solid var(--ap-color-border)', borderRadius: 4, fontSize: 'var(--ap-font-size)', outline: 'none' }
const reviewBtnStyle: React.CSSProperties = { padding: '4px 12px', border: '1px solid var(--ap-color-primary)', borderRadius: 4, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-primary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500 }
const confirmBtnStyle: React.CSSProperties = { padding: '4px 12px', border: '1px solid var(--ap-color-primary-border)', borderRadius: 4, background: 'var(--ap-color-primary-bg)', color: 'var(--ap-color-primary-active)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500 }
const cancelBtnStyle: React.CSSProperties = { padding: '4px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-text-secondary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)' }
const errorStyle: React.CSSProperties = { margin: '8px 0 0', color: 'var(--ap-color-error-text-active)', fontSize: 'var(--ap-font-size)' }
const noticeStyle: React.CSSProperties = { margin: '8px 0 0', color: 'var(--ap-color-warning-text-active)', fontSize: 'var(--ap-font-size)' }
