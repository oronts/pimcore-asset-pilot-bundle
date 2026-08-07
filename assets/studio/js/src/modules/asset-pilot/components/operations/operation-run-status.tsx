import React from 'react'
import { useTranslation } from 'react-i18next'
import type { OperationRunStatus } from '../../types'

interface OperationRunStatusTagProps {
  status: OperationRunStatus
}

export const OperationRunStatusTag: React.FC<OperationRunStatusTagProps> = ({ status }) => {
  const { t } = useTranslation()

  return <span style={statusStyle(status)}>{t('asset-pilot.operation-run.status.' + status)}</span>
}

function statusStyle(status: OperationRunStatus): React.CSSProperties {
  const colors: Record<OperationRunStatus, { background: string; color: string }> = {
    pending_dispatch: { background: 'var(--ap-color-fill-alter)', color: 'var(--ap-color-text-secondary)' },
    queued: { background: 'var(--ap-color-primary-bg)', color: 'var(--ap-color-primary-active)' },
    running: { background: 'var(--ap-color-primary-bg)', color: 'var(--ap-color-primary-active)' },
    cancel_requested: { background: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text)' },
    cancelled: { background: 'var(--ap-color-fill-alter)', color: 'var(--ap-color-text-secondary)' },
    completed: { background: 'var(--ap-color-success-bg)', color: 'var(--ap-color-success-text-active)' },
    blocked: { background: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text)' },
    partial: { background: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text)' },
    failed: { background: 'var(--ap-color-error-bg)', color: 'var(--ap-color-error-text-active)' },
  }

  return { ...colors[status], padding: '2px 8px', borderRadius: 4, fontSize: 'var(--ap-font-size)', fontWeight: 600 }
}
