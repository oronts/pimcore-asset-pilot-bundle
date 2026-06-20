import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import { useToast } from '../../hooks/use-toast'
import type { Cart } from '../../hooks/use-cart'

interface Props {
  cart: Cart
}

export const AssetCartBar: React.FC<Props> = ({ cart }) => {
  const { t } = useTranslation()
  const toast = useToast()
  const [strategy, setStrategy] = useState('folder')
  const [downloading, setDownloading] = useState(false)

  const handleDownload = async (): Promise<void> => {
    setDownloading(true)
    try {
      await assetPilotApi.downloadZip(cart.ids, { strategy })
      toast.success(t('asset-pilot.management.zip-started', { count: cart.count }))
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t('asset-pilot.management.zip-failed'))
    } finally {
      setDownloading(false)
    }
  }

  return (
    <div style={barStyle}>
      <span style={{ fontSize: 13, fontWeight: 600, color: '#ad4e00' }}>{t('asset-pilot.cart.in-cart', { count: cart.count })}</span>

      <select value={strategy} onChange={e => setStrategy(e.target.value)} aria-label={t('asset-pilot.management.zip-layout')} style={selectStyle}>
        <option value="flat">{t('asset-pilot.management.zip-flat')}</option>
        <option value="folder">{t('asset-pilot.management.zip-folder')}</option>
        <option value="type">{t('asset-pilot.management.zip-type')}</option>
      </select>

      <button onClick={() => { void handleDownload() }} disabled={downloading} style={downloadBtnStyle}>
        {downloading ? t('asset-pilot.management.zip-building') : t('asset-pilot.cart.download')}
      </button>

      <button onClick={cart.clear} style={clearBtnStyle}>{t('asset-pilot.cart.clear')}</button>
    </div>
  )
}

const barStyle: React.CSSProperties = {
  display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap',
  padding: '10px 16px', marginBottom: 12,
  background: '#fffbe6', border: '1px solid #ffe58f', borderRadius: 8,
}
const selectStyle: React.CSSProperties = { padding: '4px 8px', border: '1px solid #ffd591', borderRadius: 4, background: '#fff', color: '#ad4e00', fontSize: 12 }
const downloadBtnStyle: React.CSSProperties = { padding: '4px 12px', border: '1px solid #ffd591', borderRadius: 4, background: '#fff7e6', color: '#ad4e00', cursor: 'pointer', fontSize: 12, fontWeight: 500 }
const clearBtnStyle: React.CSSProperties = { padding: '4px 12px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff', color: '#595959', cursor: 'pointer', fontSize: 12, marginLeft: 'auto' }
