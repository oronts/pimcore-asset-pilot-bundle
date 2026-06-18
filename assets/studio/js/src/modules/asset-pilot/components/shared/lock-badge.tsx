import React from 'react'
import { useTranslation } from 'react-i18next'

export const LockBadge: React.FC = () => {
  const { t } = useTranslation()

  return (
    <span title={t('asset-pilot.lock.locked')} style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '1px 6px', background: '#fff7e6', border: '1px solid #ffd591', borderRadius: 4, fontSize: 10, color: '#d46b08', fontWeight: 600 }}>
      🔒
    </span>
  )
}
