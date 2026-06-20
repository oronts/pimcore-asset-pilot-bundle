import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import { TagPicker } from './tag-picker'
import { PropertyForm } from './property-form'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'

interface BulkAssetActionsProps {
  assetIds: number[]
  onResult: (message: string) => void
  onDeselect: () => void
  onAddToCart?: () => void
}

type ActiveForm = 'none' | 'tags' | 'property'

export const BulkAssetActions: React.FC<BulkAssetActionsProps> = ({ assetIds, onResult, onDeselect, onAddToCart }) => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const [activeForm, setActiveForm] = useState<ActiveForm>('none')
  const [lockLoading, setLockLoading] = useState(false)
  const [downloading, setDownloading] = useState(false)
  const [zipStrategy, setZipStrategy] = useState('flat')

  const handleDownloadZip = async (): Promise<void> => {
    setDownloading(true)
    try {
      await assetPilotApi.downloadZip(assetIds, { strategy: zipStrategy })
      toast.success(t('asset-pilot.management.zip-started', { count: assetIds.length }))
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t('asset-pilot.management.zip-failed'))
    } finally {
      setDownloading(false)
    }
  }

  const handleDone = (result: string): void => {
    setActiveForm('none')
    onResult(result)
  }

  const handleBulkLock = async (): Promise<void> => {
    setLockLoading(true)
    let success = 0
    let failed = 0
    for (const id of assetIds) {
      try {
        await assetPilotApi.lockAsset(id)
        success++
      } catch {
        failed++
      }
    }
    setLockLoading(false)
    if (failed > 0) {
      toast.warning(t('asset-pilot.lock.lock-success', { count: success }) + ` (${failed} failed)`)
    } else {
      toast.success(t('asset-pilot.lock.lock-success', { count: success }))
    }
    onResult(t('asset-pilot.lock.lock-success', { count: success }))
  }

  const handleBulkUnlock = async (): Promise<void> => {
    setLockLoading(true)
    let success = 0
    let failed = 0
    for (const id of assetIds) {
      try {
        await assetPilotApi.unlockAsset(id)
        success++
      } catch {
        failed++
      }
    }
    setLockLoading(false)
    if (failed > 0) {
      toast.warning(t('asset-pilot.lock.unlock-success', { count: success }) + ` (${failed} failed)`)
    } else {
      toast.success(t('asset-pilot.lock.unlock-success', { count: success }))
    }
    onResult(t('asset-pilot.lock.unlock-success', { count: success }))
  }

  return (
    <div style={{
      padding: '10px 16px', marginBottom: 12,
      background: '#e6f4ff', border: '1px solid #91caff', borderRadius: 8,
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <span style={{ fontSize: 13, fontWeight: 500, color: '#0958d9' }}>
          {t('asset-pilot.bulk.selected', { count: assetIds.length })}
        </span>

        {activeForm === 'none' && operate && (
          <div style={{ display: 'flex', gap: 8 }}>
            <button onClick={() => setActiveForm('tags')} style={tagBtnStyle}>
              {t('asset-pilot.management.assign-tags')}
            </button>
            <button onClick={() => setActiveForm('property')} style={propBtnStyle}>
              {t('asset-pilot.management.set-property')}
            </button>
            <button onClick={() => { void handleBulkLock() }} disabled={lockLoading} style={lockBtnStyle}>
              {lockLoading ? t('asset-pilot.lock.locking') : t('asset-pilot.lock.lock-selected')}
            </button>
            <button onClick={() => { void handleBulkUnlock() }} disabled={lockLoading} style={unlockBtnStyle}>
              {lockLoading ? t('asset-pilot.lock.unlocking') : t('asset-pilot.lock.unlock-selected')}
            </button>
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

      {activeForm === 'tags' && (
        <TagPicker assetIds={assetIds} onDone={handleDone} onCancel={() => setActiveForm('none')} />
      )}

      {activeForm === 'property' && (
        <PropertyForm assetIds={assetIds} onDone={handleDone} onCancel={() => setActiveForm('none')} />
      )}
    </div>
  )
}

const tagBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #b7eb8f', borderRadius: 4, background: '#f6ffed',
  color: '#389e0d', cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
const propBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #d3adf7', borderRadius: 4, background: '#f9f0ff',
  color: '#722ed1', cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
const lockBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #ffd591', borderRadius: 4, background: '#fff7e6',
  color: '#d46b08', cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
const unlockBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #87e8de', borderRadius: 4, background: '#e6fffb',
  color: '#08979c', cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
const deselectBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff',
  color: '#595959', cursor: 'pointer', fontSize: 12, marginLeft: 'auto',
}
const selectStyle: React.CSSProperties = {
  padding: '4px 8px', border: '1px solid #91caff', borderRadius: 4, background: '#fff',
  color: '#0958d9', fontSize: 12,
}
const downloadBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #91caff', borderRadius: 4, background: '#e6f4ff',
  color: '#0958d9', cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
const cartBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid #ffd591', borderRadius: 4, background: '#fff7e6',
  color: '#ad4e00', cursor: 'pointer', fontSize: 12, fontWeight: 500,
}
