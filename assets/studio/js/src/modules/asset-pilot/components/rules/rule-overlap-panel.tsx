import React from 'react'
import { useTranslation } from 'react-i18next'
import { useRuleOverlap } from '../../hooks/use-asset-pilot-api'

export const RuleOverlapPanel: React.FC = () => {
  const { t } = useTranslation()
  const { data, loading, error, refetch } = useRuleOverlap()

  if (loading) {
    return null
  }
  if (error != null) return (
    <div role="alert" style={{ background: 'var(--ap-color-error-bg)', border: '1px solid var(--ap-color-error-border)', borderRadius: 8, padding: '10px 12px', marginBottom: 16, fontSize: 'var(--ap-font-size)' }}>
      {t('asset-pilot.rules.overlap.failed', { message: error })}
      <button onClick={refetch} style={{ marginLeft: 8 }}>{t('asset-pilot.common.retry')}</button>
    </div>
  )
  if (data == null || data.overlaps.length === 0) return null

  return (
    <div style={{ background: 'var(--ap-color-warning-bg)', border: '1px solid var(--ap-color-warning-border)', borderRadius: 8, padding: '12px 16px', marginBottom: 16 }}>
      <h4 style={{ margin: '0 0 8px', fontSize: 13, fontWeight: 600, color: 'var(--ap-color-warning-text)' }}>
        {t('asset-pilot.rules.overlap.title', { count: data.overlaps.length })}
      </h4>
      <ul style={{ margin: 0, paddingLeft: 18, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>
        {data.overlaps.map((overlap, index) => (
          <li key={`${overlap.ruleA}-${overlap.ruleB}-${index}`} style={{ marginBottom: 4 }}>
            <code style={{ fontSize: 'var(--ap-font-size)' }}>{overlap.ruleA}</code>
            {' / '}
            <code style={{ fontSize: 'var(--ap-font-size)' }}>{overlap.ruleB}</code>
            {` (${overlap.class}, ${overlap.sharedFields.join(', ')}): `}
            {overlap.samePriority
              ? t('asset-pilot.rules.overlap.ambiguous')
              : t('asset-pilot.rules.overlap.winner', { rule: overlap.higherPriority ?? '' })}
          </li>
        ))}
      </ul>
    </div>
  )
}
