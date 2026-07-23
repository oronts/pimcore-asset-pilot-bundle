import React from 'react'
import { useTranslation } from 'react-i18next'
import type { ConfidenceLevel } from '../../types'

const styles: Record<ConfidenceLevel, { bg: string; border: string; text: string }> = {
  definitely_unused: { bg: 'var(--ap-color-success-bg)', border: 'var(--ap-color-success-border)', text: 'var(--ap-color-success-text)' },
  probably_unused: { bg: 'var(--ap-color-warning-bg)', border: 'var(--ap-color-warning-border)', text: 'var(--ap-color-warning-text)' },
  recently_uploaded: { bg: 'var(--ap-color-error-bg)', border: 'var(--ap-color-error-border)', text: 'var(--ap-color-error-text)' },
  historically_used: { bg: 'var(--ap-color-warning-bg)', border: 'var(--ap-color-warning-border)', text: 'var(--ap-color-warning-text-active)' },
  protected: { bg: 'var(--ap-color-fill-secondary)', border: 'var(--ap-color-border)', text: 'var(--ap-color-text-secondary)' },
}

const i18nKeys: Record<ConfidenceLevel, string> = {
  definitely_unused: 'asset-pilot.confidence.definitely-unused',
  probably_unused: 'asset-pilot.confidence.probably-unused',
  recently_uploaded: 'asset-pilot.confidence.recently-uploaded',
  historically_used: 'asset-pilot.confidence.historically-used',
  protected: 'asset-pilot.confidence.protected',
}

interface ConfidenceBadgeProps {
  confidence: ConfidenceLevel
}

export const ConfidenceBadge: React.FC<ConfidenceBadgeProps> = ({ confidence }) => {
  const { t } = useTranslation()
  const s = styles[confidence] ?? styles.probably_unused

  return (
    <span
      style={{
        display: 'inline-block',
        padding: '1px 8px',
        background: s.bg,
        border: `1px solid ${s.border}`,
        borderRadius: 4,
        fontSize: 'var(--ap-font-size)',
        fontWeight: 600,
        color: s.text,
        whiteSpace: 'nowrap',
      }}
    >
      {t(i18nKeys[confidence] ?? i18nKeys.probably_unused)}
    </span>
  )
}
