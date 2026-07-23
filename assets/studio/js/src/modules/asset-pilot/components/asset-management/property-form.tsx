import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { MutationFeedback, PlannedBulkActionResult } from '../../types'
import { MetadataPlanReview, metadataResultDetails } from './metadata-plan-review'
import { useReviewedMetadataPlan } from './use-reviewed-metadata-plan'

interface PropertyFormProps {
  assetIds: number[]
  onDone: (result: MutationFeedback) => void
  onCancel: () => void
}

interface ReviewedPropertyPlan {
  assetIds: number[]
  assetSignature: string
  name: string
  type: string
  data: string | boolean
  planToken: string
  result: PlannedBulkActionResult
}

export const PropertyForm: React.FC<PropertyFormProps> = ({ assetIds, onDone, onCancel }) => {
  const { t } = useTranslation()
  const [name, setName] = useState('')
  const [type, setType] = useState('text')
  const [value, setValue] = useState<string>('')
  const [boolValue, setBoolValue] = useState(false)
  const normalizedAssetIds = [...assetIds].sort((a, b) => a - b)
  const assetSignature = normalizedAssetIds.join(',')
  const plan = useReviewedMetadataPlan<ReviewedPropertyPlan>(assetSignature)
  const { status, reviewedPlan, error, notice } = plan

  const currentData = (): string | boolean => type === 'bool' ? boolValue : value

  const handlePreview = (): void => {
    const normalizedName = name.trim()
    if (normalizedName === '') return

    const data = currentData()
    void plan.review(
      signal => assetPilotApi.previewBulkSetProperty(normalizedAssetIds, normalizedName, type, data, signal),
      result => ({
        assetIds: normalizedAssetIds,
        assetSignature,
        name: normalizedName,
        type,
        data,
        planToken: result.planToken as string,
        result,
      }),
    )
  }

  const handleApply = (): void => {
    void plan.apply(
      (reviewed, signal) => assetPilotApi.applyBulkSetProperty(reviewed.assetIds, reviewed.name, reviewed.type, reviewed.data, reviewed.planToken, signal),
      result => {
        const summary = t('asset-pilot.management.property-result', {
          updated: result.updated ?? 0,
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
        {t('asset-pilot.management.property-title')}
      </div>

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <label htmlFor="asset-pilot-property-name" style={labelStyle}>{t('asset-pilot.management.property-name')}</label>
          <input
            id="asset-pilot-property-name"
            type="text"
            disabled={status != null}
            value={name}
            onChange={event => {
              plan.invalidate()
              setName(event.target.value)
            }}
            placeholder={t('asset-pilot.management.property-name-placeholder')}
            style={{ ...inputStyle, width: 120 }}
          />
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <label htmlFor="asset-pilot-property-type" style={labelStyle}>{t('asset-pilot.management.property-type')}</label>
          <select
            id="asset-pilot-property-type"
            value={type}
            disabled={status != null}
            onChange={event => {
              plan.invalidate()
              setType(event.target.value)
            }}
            style={inputStyle}
          >
            <option value="text">{t('asset-pilot.management.property-type-text')}</option>
            <option value="bool">{t('asset-pilot.management.property-type-bool')}</option>
            <option value="select">{t('asset-pilot.management.property-type-select')}</option>
          </select>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <label htmlFor="asset-pilot-property-value" style={labelStyle}>{t('asset-pilot.management.property-value')}</label>
          {type === 'bool' ? (
            <label style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 'var(--ap-font-size)', padding: '5px 0' }}>
              <input
                id="asset-pilot-property-value"
                type="checkbox"
                disabled={status != null}
                checked={boolValue}
                onChange={event => {
                  plan.invalidate()
                  setBoolValue(event.target.checked)
                }}
              />
              {boolValue ? t('asset-pilot.common.yes') : t('asset-pilot.common.no')}
            </label>
          ) : (
            <input
              id="asset-pilot-property-value"
              type="text"
              disabled={status != null}
              value={value}
              onChange={event => {
                plan.invalidate()
                setValue(event.target.value)
              }}
              placeholder={type === 'select'
                ? t('asset-pilot.management.property-select-placeholder')
                : t('asset-pilot.management.property-value-placeholder')}
              style={{ ...inputStyle, width: 140 }}
            />
          )}
        </div>
      </div>

      {error != null && <p role="alert" style={errorStyle}>{error}</p>}
      {notice != null && <p role="status" style={noticeStyle}>{notice}</p>}
      {reviewedPlan != null && (
        <MetadataPlanReview requested={reviewedPlan.assetIds.length} result={reviewedPlan.result} />
      )}

      <div style={{ display: 'flex', gap: 6, marginTop: 8 }}>
        <button
          onClick={handlePreview}
          disabled={status != null || name.trim() === ''}
          style={reviewBtnStyle}
        >
          {status === 'preview' ? t('asset-pilot.management.property-reviewing') : t('asset-pilot.management.property-review')}
        </button>
        {reviewedPlan != null && (
          <button
            onClick={handleApply}
            disabled={status != null}
            style={confirmBtnStyle}
          >
            {status === 'apply' ? t('asset-pilot.management.property-applying') : t('asset-pilot.management.property-apply')}
          </button>
        )}
        <button onClick={onCancel} disabled={status != null} style={cancelBtnStyle}>
          {t('asset-pilot.common.cancel')}
        </button>
      </div>
    </div>
  )
}

const labelStyle: React.CSSProperties = { fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const inputStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 6, fontSize: 'var(--ap-font-size)', outline: 'none' }
const reviewBtnStyle: React.CSSProperties = { padding: '5px 12px', border: '1px solid var(--ap-color-primary)', borderRadius: 4, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-primary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500 }
const confirmBtnStyle: React.CSSProperties = { padding: '5px 12px', border: '1px solid var(--ap-color-primary-border)', borderRadius: 4, background: 'var(--ap-color-primary-bg)', color: 'var(--ap-color-primary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500 }
const cancelBtnStyle: React.CSSProperties = { padding: '5px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-text-secondary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)' }
const errorStyle: React.CSSProperties = { margin: '8px 0 0', color: 'var(--ap-color-error-text-active)', fontSize: 'var(--ap-font-size)' }
const noticeStyle: React.CSSProperties = { margin: '8px 0 0', color: 'var(--ap-color-warning-text-active)', fontSize: 'var(--ap-font-size)' }
