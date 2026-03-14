import React from 'react'
import { useTranslation } from 'react-i18next'
import { useDashboard, useClassStats } from '../../hooks/use-asset-pilot-api'
import { StatCard } from './stat-card'
import { ClassBreakdownTable } from './class-breakdown-table'
import { RecentOperationsTable } from './recent-operations-table'
import { CardSkeleton } from '../shared/skeleton/card-skeleton'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'

interface DashboardTabProps {
  onNavigateToAudit: () => void
}

export const DashboardTab: React.FC<DashboardTabProps> = ({ onNavigateToAudit }) => {
  const { t } = useTranslation()
  const { data: dashboard, loading, error, refetch } = useDashboard()
  const { data: classStats, loading: classLoading } = useClassStats()

  if (loading) {
    return (
      <div>
        <CardSkeleton count={5} />
        <div style={{ marginTop: 28 }}><TableSkeleton rows={3} columns={6} /></div>
        <div style={{ marginTop: 28 }}><TableSkeleton rows={5} columns={6} /></div>
      </div>
    )
  }

  if (error != null) {
    return (
      <div>
        <p style={{ color: '#ff4d4f', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
        <button onClick={refetch} style={retryBtnStyle}>{t('asset-pilot.common.retry')}</button>
      </div>
    )
  }

  if (dashboard == null) return null

  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16, marginBottom: 28 }}>
        <StatCard label={t('asset-pilot.dashboard.organized')} value={dashboard.totalOrganized} color="#52c41a" />
        <StatCard label={t('asset-pilot.dashboard.pending')} value={dashboard.totalPending} color="#faad14" />
        <StatCard label={t('asset-pilot.dashboard.failed')} value={dashboard.totalFailed} color="#ff4d4f" />
        <StatCard label={t('asset-pilot.dashboard.skipped')} value={dashboard.totalSkipped} color="#8c8c8c" />
        <StatCard label={t('asset-pilot.dashboard.rules')} value={dashboard.rulesCount} color="#1677ff" />
      </div>

      <div style={{ marginBottom: 28 }}>
        <ClassBreakdownTable stats={classStats ?? []} loading={classLoading} />
      </div>

      <RecentOperationsTable operations={dashboard.recentOperations} onViewAll={onNavigateToAudit} />
    </div>
  )
}

const retryBtnStyle: React.CSSProperties = {
  padding: '6px 16px',
  border: '1px solid #d9d9d9',
  borderRadius: 6,
  background: '#fff',
  cursor: 'pointer',
  fontSize: 13,
}
