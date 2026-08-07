import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'
import { LockBadge } from './lock-badge'

interface LockCellProps {
  id: number
  onUnlocked: () => void
}

export const LockCell: React.FC<LockCellProps> = ({ id, onUnlocked }) => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const [loading, setLoading] = useState(false)

  const handleUnlock = async (): Promise<void> => {
    setLoading(true)
    try {
      await assetPilotApi.unlockAsset(id)
      toast.success(t('asset-pilot.lock.unlock-success', { count: 1 }))
      onUnlocked()
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t('asset-pilot.common.unknown-error'))
    } finally {
      setLoading(false)
    }
  }

  if (!operate) return <LockBadge />

  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
      <LockBadge />
      <button
        onClick={() => { void handleUnlock() }}
        disabled={loading}
        title={t('asset-pilot.lock.unlock')}
        style={unlockRowBtnStyle}
      >
        {loading ? t('asset-pilot.lock.unlocking') : t('asset-pilot.lock.unlock')}
      </button>
    </span>
  )
}

const unlockRowBtnStyle: React.CSSProperties = {
  padding: '1px 8px', border: '1px solid var(--ap-color-info-border)', borderRadius: 4,
  background: 'var(--ap-color-info-bg)', color: 'var(--ap-color-info-text)',
  cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
