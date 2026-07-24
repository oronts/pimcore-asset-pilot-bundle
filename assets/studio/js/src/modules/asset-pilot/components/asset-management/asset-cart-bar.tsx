import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import { useToast } from '../../hooks/use-toast'
import type { Cart } from '../../hooks/use-cart'
import { OpenButton } from '../shared/open-button'
import { theme } from 'antd'

interface Props {
  cart: Cart
}

export const AssetCartBar: React.FC<Props> = ({ cart }) => {
  const { t } = useTranslation()
  const { token } = theme.useToken()
  const toast = useToast()
  const [strategy, setStrategy] = useState('')
  const [downloading, setDownloading] = useState(false)

  const handleDownload = async (): Promise<void> => {
    setDownloading(true)
    try {
      await assetPilotApi.downloadZip(cart.ids, strategy === '' ? {} : { strategy })
      toast.success(t('asset-pilot.management.zip-started', { count: cart.count }))
    } catch (e) {
      toast.error(e instanceof Error ? e.message : t('asset-pilot.management.zip-failed'))
    } finally {
      setDownloading(false)
    }
  }

  return (
    <div style={{
      display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap', padding: '10px 16px',
      marginBottom: 12, background: token.colorWarningBg, border: `1px solid ${token.colorWarningBorder}`,
      borderRadius: token.borderRadiusLG,
    }}>
      <span style={{ fontSize: token.fontSize, fontWeight: 600, color: token.colorWarningText }}>{t('asset-pilot.cart.in-cart', { count: cart.count })}</span>

      <select value={strategy} onChange={e => setStrategy(e.target.value)} aria-label={t('asset-pilot.management.zip-layout')} style={{ padding: '4px 8px', border: `1px solid ${token.colorWarningBorder}`, borderRadius: token.borderRadius, background: token.colorBgContainer, color: token.colorText, fontSize: token.fontSize }}>
        <option value="">{t('asset-pilot.management.zip-server-default')}</option>
        <option value="flat">{t('asset-pilot.management.zip-flat')}</option>
        <option value="folder">{t('asset-pilot.management.zip-folder')}</option>
        <option value="type">{t('asset-pilot.management.zip-type')}</option>
      </select>

      <button onClick={() => { void handleDownload() }} disabled={downloading} style={{ padding: '4px 12px', border: `1px solid ${token.colorWarningBorder}`, borderRadius: token.borderRadius, background: token.colorWarningBg, color: token.colorWarningText, cursor: 'pointer', fontSize: token.fontSize, fontWeight: 500 }}>
        {downloading ? t('asset-pilot.management.zip-building') : t('asset-pilot.cart.download')}
      </button>

      <button onClick={cart.clear} style={{ padding: '4px 12px', border: `1px solid ${token.colorBorder}`, borderRadius: token.borderRadius, background: token.colorBgContainer, color: token.colorTextSecondary, cursor: 'pointer', fontSize: token.fontSize, marginLeft: 'auto' }}>{t('asset-pilot.cart.clear')}</button>

      <details style={{ width: '100%', color: token.colorText }}>
        <summary style={{ cursor: 'pointer', fontSize: token.fontSize, fontWeight: 500 }}>
          {t('asset-pilot.cart.items')}
        </summary>
        <ul aria-label={t('asset-pilot.cart.items')} style={{ listStyle: 'none', padding: 0, margin: '8px 0 0', maxHeight: 240, overflowY: 'auto', borderTop: `1px solid ${token.colorWarningBorder}` }}>
          {cart.ids.map(id => (
            <li key={id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '6px 0', borderBottom: `1px solid ${token.colorBorderSecondary}` }}>
              <OpenButton id={id} type="asset" label={`#${id}`} />
              <button
                type="button"
                aria-label={t('asset-pilot.cart.remove-id', { id })}
                onClick={() => cart.remove(id)}
                style={{ marginLeft: 'auto', padding: '3px 8px', border: `1px solid ${token.colorBorder}`, borderRadius: token.borderRadiusSM, background: token.colorBgContainer, color: token.colorTextSecondary, cursor: 'pointer', fontSize: token.fontSize }}
              >
                {t('asset-pilot.cart.remove')}
              </button>
            </li>
          ))}
        </ul>
      </details>
    </div>
  )
}
