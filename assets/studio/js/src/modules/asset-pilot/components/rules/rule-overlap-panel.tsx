import React from 'react'
import { useTranslation } from 'react-i18next'
import { useRuleOverlap } from '../../hooks/use-asset-pilot-api'

export const RuleOverlapPanel: React.FC = () => {
  const { t } = useTranslation()
  const { data, loading, error } = useRuleOverlap()

  if (loading || error != null || data == null || data.overlaps.length === 0) {
    return null
  }

  return (
    <div style={{ background: '#fffbe6', border: '1px solid #ffe58f', borderRadius: 8, padding: '12px 16px', marginBottom: 16 }}>
      <h4 style={{ margin: '0 0 8px', fontSize: 13, fontWeight: 600, color: '#ad8b00' }}>
        {t('asset-pilot.rules.overlap.title', { count: data.overlaps.length })}
      </h4>
      <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12, color: '#595959' }}>
        {data.overlaps.map((overlap, index) => (
          <li key={`${overlap.ruleA}-${overlap.ruleB}-${index}`} style={{ marginBottom: 4 }}>
            <code style={{ fontSize: 11 }}>{overlap.ruleA}</code>
            {' / '}
            <code style={{ fontSize: 11 }}>{overlap.ruleB}</code>
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
