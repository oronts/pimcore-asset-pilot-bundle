import React from 'react'
import { useTranslation } from 'react-i18next'
import type { ConfidenceLevel } from '../../types'

const styles: Record<ConfidenceLevel, { bg: string; border: string; text: string }> = {
  definitely_unused: { bg: '#f6ffed', border: '#b7eb8f', text: '#389e0d' },
  probably_unused: { bg: '#fffbe6', border: '#ffe58f', text: '#d48806' },
  recently_uploaded: { bg: '#fff2f0', border: '#ffccc7', text: '#cf1322' },
  historically_used: { bg: '#fff7e6', border: '#ffd591', text: '#d46b08' },
  protected: { bg: '#f5f5f5', border: '#d9d9d9', text: '#8c8c8c' },
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
        fontSize: 10,
        fontWeight: 600,
        color: s.text,
        whiteSpace: 'nowrap',
      }}
    >
      {t(i18nKeys[confidence] ?? i18nKeys.probably_unused)}
    </span>
  )
}
