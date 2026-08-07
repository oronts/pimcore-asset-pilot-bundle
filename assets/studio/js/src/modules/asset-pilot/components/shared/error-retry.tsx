import React from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from 'antd'

export const ErrorRetry: React.FC<{ error: string; onRetry?: () => void }> = ({ error, onRetry }) => {
  const { t } = useTranslation()
  const { token } = theme.useToken()

  return (
    <p role="alert" style={{ color: token.colorError, fontSize: token.fontSize }}>
      {error}
      {onRetry != null && (
        <button
          type="button"
          onClick={onRetry}
          style={{ marginLeft: 8, padding: '2px 10px', border: `1px solid ${token.colorBorder}`, borderRadius: token.borderRadius, background: token.colorBgContainer, color: token.colorText, cursor: 'pointer', fontSize: token.fontSize }}
        >
          {t('asset-pilot.common.retry')}
        </button>
      )}
    </p>
  )
}
