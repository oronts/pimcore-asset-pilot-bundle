import React from 'react'
import { useTranslation } from 'react-i18next'

const statusStyles: Record<string, { bg: string; color: string; key: string }> = {
  completed: { bg: 'var(--ap-color-success-bg)', color: 'var(--ap-color-success-text)', key: 'asset-pilot.status.completed' },
  completed_with_observer_error: { bg: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text)', key: 'asset-pilot.status.completed_with_observer_error' },
  in_progress: { bg: 'var(--ap-color-info-bg)', color: 'var(--ap-color-info-text)', key: 'asset-pilot.status.in_progress' },
  recovery_required: { bg: 'var(--ap-color-error-bg)', color: 'var(--ap-color-error-text)', key: 'asset-pilot.status.recovery_required' },
  failed: { bg: 'var(--ap-color-error-bg)', color: 'var(--ap-color-error-text)', key: 'asset-pilot.status.failed' },
  skipped: { bg: 'var(--ap-color-fill-alter)', color: 'var(--ap-color-text-secondary)', key: 'asset-pilot.status.skipped' },
  pending: { bg: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text)', key: 'asset-pilot.status.pending' },
}

interface StatusTagProps {
  status: string
}

export const StatusTag: React.FC<StatusTagProps> = ({ status }) => {
  const { t } = useTranslation()
  const config = statusStyles[status] ?? { bg: 'var(--ap-color-fill-secondary)', color: 'var(--ap-color-text-secondary)', key: '' }

  return (
    <span style={{
      background: config.bg,
      color: config.color,
      padding: '1px 8px',
      borderRadius: 4,
      fontSize: 'var(--ap-font-size)',
      fontWeight: 500,
      display: 'inline-block',
    }}>
      {config.key ? t(config.key) : status}
    </span>
  )
}

const triggerConfig: Record<string, { bg: string; color: string }> = {
  object_save: { bg: 'var(--ap-color-info-bg)', color: 'var(--ap-color-primary)' },
  asset_upload: { bg: 'var(--ap-color-primary-bg)', color: 'var(--ap-color-primary)' },
  bulk_operation: { bg: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text)' },
  manual: { bg: 'var(--ap-color-success-bg)', color: 'var(--ap-color-success-text)' },
  scheduled: { bg: 'var(--ap-color-fill-secondary)', color: 'var(--ap-color-text-secondary)' },
  api: { bg: 'var(--ap-color-info-bg)', color: 'var(--ap-color-info)' },
}

interface TriggerTagProps {
  trigger: string
}

export const TriggerTag: React.FC<TriggerTagProps> = ({ trigger }) => {
  const config = triggerConfig[trigger] ?? { bg: 'var(--ap-color-fill-secondary)', color: 'var(--ap-color-text-secondary)' }

  return (
    <span style={{
      background: config.bg,
      color: config.color,
      padding: '1px 8px',
      borderRadius: 4,
      fontSize: 'var(--ap-font-size)',
      fontWeight: 500,
      display: 'inline-block',
    }}>
      {trigger}
    </span>
  )
}

const strategyConfig: Record<string, { bg: string; color: string }> = {
  always: { bg: 'var(--ap-color-info-bg)', color: 'var(--ap-color-primary)' },
  first_assignment: { bg: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text)' },
  callback: { bg: 'var(--ap-color-primary-bg)', color: 'var(--ap-color-primary)' },
}

interface StrategyTagProps {
  strategy: string
}

export const StrategyTag: React.FC<StrategyTagProps> = ({ strategy }) => {
  const config = strategyConfig[strategy] ?? { bg: 'var(--ap-color-fill-secondary)', color: 'var(--ap-color-text-secondary)' }

  return (
    <span style={{
      background: config.bg,
      color: config.color,
      padding: '1px 8px',
      borderRadius: 4,
      fontSize: 'var(--ap-font-size)',
      fontWeight: 500,
      display: 'inline-block',
    }}>
      {strategy}
    </span>
  )
}
