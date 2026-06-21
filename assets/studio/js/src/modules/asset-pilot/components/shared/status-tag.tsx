import React from 'react'
import { useTranslation } from 'react-i18next'

const statusStyles: Record<string, { bg: string; color: string; key: string }> = {
  completed: { bg: '#f6ffed', color: '#52c41a', key: 'asset-pilot.status.completed' },
  failed: { bg: '#fff2f0', color: '#ff4d4f', key: 'asset-pilot.status.failed' },
  skipped: { bg: '#fafafa', color: '#8c8c8c', key: 'asset-pilot.status.skipped' },
  pending: { bg: '#fffbe6', color: '#faad14', key: 'asset-pilot.status.pending' },
  action_failed: { bg: '#fff1f0', color: '#cf1322', key: 'asset-pilot.status.action_failed' },
}

interface StatusTagProps {
  status: string
}

export const StatusTag: React.FC<StatusTagProps> = ({ status }) => {
  const { t } = useTranslation()
  const config = statusStyles[status] ?? { bg: '#f5f5f5', color: '#595959', key: '' }

  return (
    <span style={{
      background: config.bg,
      color: config.color,
      padding: '1px 8px',
      borderRadius: 4,
      fontSize: 12,
      fontWeight: 500,
      display: 'inline-block',
    }}>
      {config.key ? t(config.key) : status}
    </span>
  )
}

const triggerConfig: Record<string, { bg: string; color: string }> = {
  object_save: { bg: '#e6f7ff', color: '#1677ff' },
  asset_upload: { bg: '#f9f0ff', color: '#722ed1' },
  bulk_operation: { bg: '#fff7e6', color: '#fa8c16' },
  manual: { bg: '#f6ffed', color: '#52c41a' },
  scheduled: { bg: '#f5f5f5', color: '#595959' },
  api: { bg: '#e6fffb', color: '#13c2c2' },
}

interface TriggerTagProps {
  trigger: string
}

export const TriggerTag: React.FC<TriggerTagProps> = ({ trigger }) => {
  const config = triggerConfig[trigger] ?? { bg: '#f5f5f5', color: '#595959' }

  return (
    <span style={{
      background: config.bg,
      color: config.color,
      padding: '1px 8px',
      borderRadius: 4,
      fontSize: 12,
      fontWeight: 500,
      display: 'inline-block',
    }}>
      {trigger}
    </span>
  )
}

const strategyConfig: Record<string, { bg: string; color: string }> = {
  always: { bg: '#e6f7ff', color: '#1677ff' },
  first_assignment: { bg: '#fff7e6', color: '#fa8c16' },
  callback: { bg: '#f9f0ff', color: '#722ed1' },
}

interface StrategyTagProps {
  strategy: string
}

export const StrategyTag: React.FC<StrategyTagProps> = ({ strategy }) => {
  const config = strategyConfig[strategy] ?? { bg: '#f5f5f5', color: '#595959' }

  return (
    <span style={{
      background: config.bg,
      color: config.color,
      padding: '1px 8px',
      borderRadius: 4,
      fontSize: 12,
      fontWeight: 500,
      display: 'inline-block',
    }}>
      {strategy}
    </span>
  )
}
