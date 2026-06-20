import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'

interface BulkActionBarProps {
  count: number
  loading: boolean
  assetIds: number[]
  onDelete: () => void
  onMove: (targetFolder: string) => void
  onQuarantine: () => void
  onDeselect: () => void
  onLockDone?: () => void
}

export const BulkActionBar: React.FC<BulkActionBarProps> = ({ count, loading, assetIds, onDelete, onMove, onQuarantine, onDeselect, onLockDone }) => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false)
  const [showMoveForm, setShowMoveForm] = useState(false)
  const [targetFolder, setTargetFolder] = useState('/archive/unused')
  const [showMoveConfirm, setShowMoveConfirm] = useState(false)
  const [showQuarantineConfirm, setShowQuarantineConfirm] = useState(false)
  const [lockLoading, setLockLoading] = useState(false)

  const handleBulkLock = async (): Promise<void> => {
    setLockLoading(true)
    let success = 0
    for (const id of assetIds) {
      try {
        await assetPilotApi.lockAsset(id)
        success++
      } catch { /* skip */ }
    }
    setLockLoading(false)
    const failed = assetIds.length - success
    if (failed > 0) toast.warning(t('asset-pilot.lock.lock-partial', { success, failed }))
    else toast.success(t('asset-pilot.lock.lock-success', { count: success }))
    onLockDone?.()
  }

  const handleBulkUnlock = async (): Promise<void> => {
    setLockLoading(true)
    let success = 0
    for (const id of assetIds) {
      try {
        await assetPilotApi.unlockAsset(id)
        success++
      } catch { /* skip */ }
    }
    setLockLoading(false)
    const failed = assetIds.length - success
    if (failed > 0) toast.warning(t('asset-pilot.lock.unlock-partial', { success, failed }))
    else toast.success(t('asset-pilot.lock.unlock-success', { count: success }))
    onLockDone?.()
  }

  const isDisabled = loading || lockLoading

  return (
    <div style={{
      display: 'flex', alignItems: 'center', gap: 12, padding: '10px 16px', marginBottom: 12,
      background: '#e6f4ff', border: '1px solid #91caff', borderRadius: 8, flexWrap: 'wrap',
    }}>
      <span style={{ fontSize: 13, fontWeight: 500, color: '#0958d9' }}>
        {t('asset-pilot.bulk.selected', { count })}
      </span>

      <div style={{ display: 'flex', gap: 8, flex: 1, flexWrap: 'wrap' }}>
        {!showMoveForm && operate && (
          <>
            <button onClick={() => setShowDeleteConfirm(true)} disabled={isDisabled} style={deleteBtnStyle}>
              {t('asset-pilot.bulk.delete')}
            </button>
            <button onClick={() => setShowMoveForm(true)} disabled={isDisabled} style={moveBtnStyle}>
              {t('asset-pilot.bulk.move')}
            </button>
            <button onClick={() => setShowQuarantineConfirm(true)} disabled={isDisabled} style={quarantineBtnStyle}>
              {t('asset-pilot.bulk.quarantine')}
            </button>
            <button onClick={() => { void handleBulkLock() }} disabled={isDisabled} style={lockBtnStyle}>
              {lockLoading ? t('asset-pilot.lock.locking') : t('asset-pilot.lock.lock-selected')}
            </button>
            <button onClick={() => { void handleBulkUnlock() }} disabled={isDisabled} style={unlockBtnStyle}>
              {lockLoading ? t('asset-pilot.lock.unlocking') : t('asset-pilot.lock.unlock-selected')}
            </button>
          </>
        )}

        {showMoveForm && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <input
              type="text"
              value={targetFolder}
              onChange={e => setTargetFolder(e.target.value)}
              placeholder={t('asset-pilot.bulk.target-folder')}
              style={{ padding: '4px 8px', border: '1px solid #d9d9d9', borderRadius: 4, fontSize: 12, width: 180 }}
            />
            <button
              onClick={() => setShowMoveConfirm(true)}
              disabled={isDisabled || !targetFolder}
              style={moveBtnStyle}
            >
              {t('asset-pilot.bulk.confirm-move')}
            </button>
            <button onClick={() => setShowMoveForm(false)} disabled={isDisabled} style={cancelBtnStyle}>
              {t('asset-pilot.common.cancel')}
            </button>
          </div>
        )}
      </div>

      <button onClick={onDeselect} style={cancelBtnStyle}>{t('asset-pilot.bulk.deselect-all')}</button>

      {showDeleteConfirm && (
        <ConfirmDialog
          title={t('asset-pilot.confirm.delete-title')}
          description={t('asset-pilot.confirm.delete-description', { count })}
          confirmLabel={t('asset-pilot.bulk.confirm-delete')}
          cancelLabel={t('asset-pilot.common.cancel')}
          variant="danger"
          loading={loading}
          onConfirm={() => { onDelete(); setShowDeleteConfirm(false) }}
          onCancel={() => setShowDeleteConfirm(false)}
        />
      )}

      {showMoveConfirm && (
        <ConfirmDialog
          title={t('asset-pilot.confirm.move-title')}
          description={t('asset-pilot.confirm.move-description', { count, folder: targetFolder })}
          confirmLabel={t('asset-pilot.bulk.confirm-move')}
          cancelLabel={t('asset-pilot.common.cancel')}
          variant="warning"
          loading={loading}
          onConfirm={() => { onMove(targetFolder); setShowMoveConfirm(false); setShowMoveForm(false) }}
          onCancel={() => setShowMoveConfirm(false)}
        />
      )}

      {showQuarantineConfirm && (
        <ConfirmDialog
          title={t('asset-pilot.confirm.quarantine-title')}
          description={t('asset-pilot.confirm.quarantine-description', { count })}
          confirmLabel={t('asset-pilot.bulk.confirm-quarantine')}
          cancelLabel={t('asset-pilot.common.cancel')}
          variant="warning"
          loading={loading}
          onConfirm={() => { onQuarantine(); setShowQuarantineConfirm(false) }}
          onCancel={() => setShowQuarantineConfirm(false)}
        />
      )}
    </div>
  )
}

const deleteBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #ff7875', borderRadius: 4, background: '#fff2f0',
  color: '#cf1322', cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
const moveBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #91caff', borderRadius: 4, background: '#e6f4ff',
  color: '#0958d9', cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
const quarantineBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #ffc069', borderRadius: 4, background: '#fff7e6',
  color: '#ad6800', cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
const lockBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #ffd591', borderRadius: 4, background: '#fff7e6',
  color: '#d46b08', cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
const unlockBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #87e8de', borderRadius: 4, background: '#e6fffb',
  color: '#08979c', cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
const cancelBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff',
  color: '#595959', cursor: 'pointer', fontSize: 12,
}
