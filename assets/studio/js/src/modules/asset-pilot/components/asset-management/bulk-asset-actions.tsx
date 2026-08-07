import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import { TagPicker } from './tag-picker'
import { PropertyForm } from './property-form'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'
import type { MutationFeedback } from '../../types'

interface BulkAssetActionsProps {
  assetIds: number[]
  lockedIds?: number[]
  onResult: (result: MutationFeedback) => void
  onDeselect: () => void
  onAddToCart?: () => void
}

type ActiveForm = 'none' | 'tags' | 'property'

export const BulkAssetActions: React.FC<BulkAssetActionsProps> = ({ assetIds, lockedIds = [], onResult, onDeselect, onAddToCart }) => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate, tagsAssignment } = usePermissions()
  const [activeForm, setActiveForm] = useState<ActiveForm>('none')
  const [lockLoading, setLockLoading] = useState(false)
  const [downloading, setDownloading] = useState(false)
  const [zipStrategy, setZipStrategy] = useState('')

  // Metadata edits (tags, property, lock) skip protected assets; ZIP, cart, and unlock apply to all.
  const lockedSet = React.useMemo(() => new Set(lockedIds), [lockedIds])
  const mutableIds = React.useMemo(() => assetIds.filter(id => !lockedSet.has(id)), [assetIds, lockedSet])
  const protectedCount = assetIds.length - mutableIds.length
  const hasMutable = mutableIds.length > 0

  const handleDownloadZip = async (): Promise<void> => {
    setDownloading(true)
    try {
      await assetPilotApi.downloadZip(assetIds, zipStrategy === '' ? {} : { strategy: zipStrategy })
      toast.success(t('asset-pilot.management.zip-started', { count: assetIds.length }))
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t('asset-pilot.management.zip-failed'))
    } finally {
      setDownloading(false)
    }
  }

  const handleDone = (result: MutationFeedback): void => {
    if (result.severity !== 'error') setActiveForm('none')
    onResult(result)
  }

  const runLockAction = async (ids: number[], action: (id: number) => Promise<unknown>): Promise<{ success: number; failed: number }> => {
    let success = 0
    let next = 0
    const workers = Array.from({ length: Math.min(6, ids.length) }, async () => {
      while (next < ids.length) {
        const id = ids[next++]
        try {
          await action(id)
          success++
        } catch {
        }
      }
    })
    await Promise.all(workers)
    return { success, failed: ids.length - success }
  }

  const handleBulkLock = async (): Promise<void> => {
    setLockLoading(true)
    const { success, failed } = await runLockAction(mutableIds, assetPilotApi.lockAsset)
    setLockLoading(false)
    onResult({
      severity: failed > 0 ? 'warning' : 'success',
      message: failed > 0
        ? t('asset-pilot.lock.lock-partial', { success, failed })
        : t('asset-pilot.lock.lock-success', { count: success }),
    })
  }

  const handleBulkUnlock = async (): Promise<void> => {
    setLockLoading(true)
    const { success, failed } = await runLockAction(assetIds, assetPilotApi.unlockAsset)
    setLockLoading(false)
    onResult({
      severity: failed > 0 ? 'warning' : 'success',
      message: failed > 0
        ? t('asset-pilot.lock.unlock-partial', { success, failed })
        : t('asset-pilot.lock.unlock-success', { count: success }),
    })
  }

  return (
    <div style={{
      padding: '10px 16px', marginBottom: 12,
      background: 'var(--ap-color-primary-bg)', border: '1px solid var(--ap-color-primary-border)', borderRadius: 8,
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <span style={{ fontSize: 13, fontWeight: 500, color: 'var(--ap-color-primary-active)' }}>
          {t('asset-pilot.bulk.selected', { count: assetIds.length })}
        </span>

        {activeForm === 'none' && operate && (
          <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
            {tagsAssignment && (
              <button onClick={() => setActiveForm('tags')} disabled={!hasMutable} style={tagBtnStyle}>
                {t('asset-pilot.management.assign-tags')}
              </button>
            )}
            <button onClick={() => setActiveForm('property')} disabled={!hasMutable} style={propBtnStyle}>
              {t('asset-pilot.management.set-property')}
            </button>
            <button onClick={() => { void handleBulkLock() }} disabled={lockLoading || !hasMutable} style={lockBtnStyle}>
              {lockLoading ? t('asset-pilot.lock.locking') : t('asset-pilot.lock.lock-selected')}
            </button>
            <button onClick={() => { void handleBulkUnlock() }} disabled={lockLoading} style={unlockBtnStyle}>
              {lockLoading ? t('asset-pilot.lock.unlocking') : t('asset-pilot.lock.unlock-selected')}
            </button>
            {protectedCount > 0 && (
              <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>
                {t('asset-pilot.bulk.protected-skipped', { count: protectedCount })}
              </span>
            )}
          </div>
        )}

        {activeForm === 'none' && (
          <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
            <select
              value={zipStrategy}
              onChange={e => setZipStrategy(e.target.value)}
              aria-label={t('asset-pilot.management.zip-layout')}
              style={selectStyle}
            >
              <option value="">{t('asset-pilot.management.zip-server-default')}</option>
              <option value="flat">{t('asset-pilot.management.zip-flat')}</option>
              <option value="folder">{t('asset-pilot.management.zip-folder')}</option>
              <option value="type">{t('asset-pilot.management.zip-type')}</option>
            </select>
            <button onClick={() => { void handleDownloadZip() }} disabled={downloading} style={downloadBtnStyle}>
              {downloading ? t('asset-pilot.management.zip-building') : t('asset-pilot.management.download-zip')}
            </button>
          </div>
        )}

        {activeForm === 'none' && onAddToCart != null && (
          <button onClick={onAddToCart} style={cartBtnStyle}>{t('asset-pilot.cart.add')}</button>
        )}

        <button onClick={onDeselect} style={deselectBtnStyle}>
          {t('asset-pilot.bulk.deselect-all')}
        </button>
      </div>

      {activeForm === 'tags' && tagsAssignment && (
        <TagPicker assetIds={mutableIds} onDone={handleDone} onCancel={() => setActiveForm('none')} />
      )}

      {activeForm === 'property' && (
        <PropertyForm assetIds={mutableIds} onDone={handleDone} onCancel={() => setActiveForm('none')} />
      )}
    </div>
  )
}

const tagBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-success-border)', borderRadius: 4, background: 'var(--ap-color-success-bg)',
  color: 'var(--ap-color-success-text)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
const propBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-primary-border)', borderRadius: 4, background: 'var(--ap-color-primary-bg)',
  color: 'var(--ap-color-primary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
const lockBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-warning-border)', borderRadius: 4, background: 'var(--ap-color-warning-bg)',
  color: 'var(--ap-color-warning-text-active)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
const unlockBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-info-border)', borderRadius: 4, background: 'var(--ap-color-info-bg)',
  color: 'var(--ap-color-info-text)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
const deselectBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)',
  color: 'var(--ap-color-text-secondary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', marginLeft: 'auto',
}
const selectStyle: React.CSSProperties = {
  padding: '4px 8px', border: '1px solid var(--ap-color-primary-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)',
  color: 'var(--ap-color-primary-active)', fontSize: 'var(--ap-font-size)',
}
const downloadBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-primary-border)', borderRadius: 4, background: 'var(--ap-color-primary-bg)',
  color: 'var(--ap-color-primary-active)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
const cartBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-warning-border)', borderRadius: 4, background: 'var(--ap-color-warning-bg)',
  color: 'var(--ap-color-warning-text)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
