import React from 'react'
import { useTranslation } from 'react-i18next'
import type { PlannedBulkActionResult } from '../../types'

interface MetadataPlanReviewProps {
  requested: number
  result: PlannedBulkActionResult
}

export const MetadataPlanReview: React.FC<MetadataPlanReviewProps> = ({ requested, result }) => {
  const { t } = useTranslation()
  const errors = Object.entries(result.errors)
  const blocked = Math.max(0, requested - result.eligible)

  return (
    <section
      role="status"
      aria-live="polite"
      aria-label={t('asset-pilot.management.review-result')}
      style={reviewStyle}
    >
      <strong style={{ display: 'block', marginBottom: 4 }}>
        {t('asset-pilot.management.review-eligibility', { eligible: result.eligible, blocked })}
      </strong>
      {errors.length > 0 && (
        <ul style={listStyle}>
          {errors.map(([assetId, reason]) => (
            <li key={assetId}>
              {t('asset-pilot.management.review-asset-error', { id: assetId, reason })}
            </li>
          ))}
        </ul>
      )}
      {(result.observerWarnings ?? []).length > 0 && (
        <ul style={warningListStyle}>
          {(result.observerWarnings ?? []).map((warning, index) => <li key={index}>{warning}</li>)}
        </ul>
      )}
    </section>
  )
}

export function metadataResultDetails(
  result: PlannedBulkActionResult,
  formatError: (assetId: string, reason: string) => string,
): string {
  const errors = Object.entries(result.errors)
    .map(([assetId, reason]) => formatError(assetId, reason))
    .join('; ')
  const warnings = (result.observerWarnings ?? []).join('; ')

  return [errors, warnings].filter(part => part !== '').join(' ')
}

const reviewStyle: React.CSSProperties = {
  marginTop: 8,
  padding: 8,
  border: '1px solid var(--ap-color-primary-border)',
  borderRadius: 6,
  background: 'var(--ap-color-info-bg)',
  color: 'var(--ap-color-text)',
  fontSize: 'var(--ap-font-size)',
}

const listStyle: React.CSSProperties = {
  margin: '4px 0 0',
  paddingLeft: 18,
  color: 'var(--ap-color-error-text)',
}

const warningListStyle: React.CSSProperties = {
  margin: '4px 0 0',
  paddingLeft: 18,
  color: 'var(--ap-color-warning-text)',
}
