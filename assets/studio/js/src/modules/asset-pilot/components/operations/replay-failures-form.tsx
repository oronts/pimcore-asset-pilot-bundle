import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'

export const ReplayFailuresForm: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const [running, setRunning] = useState(false)
  const [queueAsync, setQueueAsync] = useState(true)

  if (!operate) {
    return null
  }

  const handleReplay = async (): Promise<void> => {
    setRunning(true)
    try {
      const result = await assetPilotApi.replayFailures({ async: queueAsync })
      if (queueAsync) {
        toast.success(t('asset-pilot.replay.queued', { count: result.dispatched }))
      } else if (result.failed > 0) {
        toast.warning(t('asset-pilot.replay.partial', { organized: result.organized, failed: result.failed }))
      } else {
        toast.success(t('asset-pilot.replay.done', { count: result.organized }))
      }
    } catch {
      toast.error(t('asset-pilot.replay.failed'))
    } finally {
      setRunning(false)
    }
  }

  return (
    <div>
      <h4 style={{ margin: '0 0 4px', fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.replay.title')}</h4>
      <p style={{ margin: '0 0 12px', fontSize: 12, color: '#8c8c8c' }}>{t('asset-pilot.replay.desc')}</p>
      <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, marginBottom: 12 }}>
        <input type="checkbox" checked={queueAsync} onChange={event => setQueueAsync(event.target.checked)} />
        {t('asset-pilot.replay.async')}
      </label>
      <button onClick={handleReplay} disabled={running} style={btnStyle}>
        {running ? t('asset-pilot.common.loading') : t('asset-pilot.replay.button')}
      </button>
    </div>
  )
}

const btnStyle: React.CSSProperties = {
  padding: '6px 16px',
  border: '1px solid #d9d9d9',
  borderRadius: 6,
  background: '#fff',
  cursor: 'pointer',
  fontSize: 13,
}
