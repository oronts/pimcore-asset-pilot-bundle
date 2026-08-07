import React from 'react'
import { useTranslation } from 'react-i18next'

export const LockBadge: React.FC = () => {
  const { t } = useTranslation()

  return (
    <span title={t('asset-pilot.lock.locked')} style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '1px 6px', background: 'var(--ap-color-warning-bg)', border: '1px solid var(--ap-color-warning-border)', borderRadius: 4, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-warning-text-active)', fontWeight: 600 }}>
      🔒
    </span>
  )
}
