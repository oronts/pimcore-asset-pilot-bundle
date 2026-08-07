import React from 'react'
import { useTranslation } from 'react-i18next'
import { useDashboard, useClassStats } from '../../hooks/use-asset-pilot-api'
import { StatCard } from './stat-card'
import { ClassBreakdownTable } from './class-breakdown-table'
import { RecentOperationsTable } from './recent-operations-table'
import { HealthPanel } from './health-panel'
import { CardSkeleton } from '../shared/skeleton/card-skeleton'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { DonutChart } from '../shared/charts/donut-chart'
import { BarChart } from '../shared/charts/bar-chart'

interface DashboardTabProps {
  onNavigateToAudit: () => void
}

export const DashboardTab: React.FC<DashboardTabProps> = ({ onNavigateToAudit }) => {
  const { t } = useTranslation()
  const { data: dashboard, loading, error, refetch } = useDashboard()
  const { data: classStats, loading: classLoading, error: classError, refetch: refetchClasses } = useClassStats()

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
        <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
        <button onClick={refetch} style={retryBtnStyle}>{t('asset-pilot.common.retry')}</button>
      </div>
    )
  }

  if (dashboard == null) return null

  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16, marginBottom: 28 }}>
        <StatCard label={t('asset-pilot.dashboard.organized')} value={dashboard.totalOrganized} color="var(--ap-color-success-text)" />
        <StatCard label={t('asset-pilot.dashboard.pending')} value={dashboard.totalPending} color="var(--ap-color-warning-text)" />
        <StatCard label={t('asset-pilot.dashboard.failed')} value={dashboard.totalFailed} color="var(--ap-color-error-text)" />
        <StatCard label={t('asset-pilot.dashboard.skipped')} value={dashboard.totalSkipped} color="var(--ap-color-text-secondary)" />
        <StatCard label={t('asset-pilot.dashboard.rules')} value={dashboard.rulesCount} color="var(--ap-color-primary)" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 16, marginBottom: 28 }}>
        <Panel title={t('asset-pilot.dashboard.ops-by-status')}>
          <DonutChart
            centerLabel={t('asset-pilot.dashboard.operations')}
            segments={[
              { label: t('asset-pilot.dashboard.organized'), value: dashboard.totalOrganized, color: 'var(--ap-color-success-text)' },
              { label: t('asset-pilot.dashboard.skipped'), value: dashboard.totalSkipped, color: 'var(--ap-color-text-secondary)' },
              { label: t('asset-pilot.dashboard.failed'), value: dashboard.totalFailed, color: 'var(--ap-color-error-text)' },
              { label: t('asset-pilot.dashboard.pending'), value: dashboard.totalPending, color: 'var(--ap-color-warning-text)' },
            ]}
          />
        </Panel>
        <Panel title={t('asset-pilot.dashboard.assets-by-class')}>
          {classError != null
            ? <p role="alert" style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-error-text-active)' }}>{t('asset-pilot.common.error', { message: classError })} <button onClick={refetchClasses}>{t('asset-pilot.common.retry')}</button></p>
            : (classStats ?? []).length > 0
            ? <BarChart items={[...(classStats ?? [])].sort((a, b) => b.total - a.total).slice(0, 8).map(c => ({ label: c.className, value: c.total }))} />
            : <p style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', margin: 0 }}>{t('asset-pilot.dashboard.no-class-data')}</p>}
        </Panel>
      </div>

      <div style={{ marginBottom: 28 }}>
        <HealthPanel />
      </div>

      <div style={{ marginBottom: 28 }}>
        {classError == null && <ClassBreakdownTable stats={classStats ?? []} loading={classLoading} />}
      </div>

      <RecentOperationsTable operations={dashboard.recentOperations} onViewAll={onNavigateToAudit} />
    </div>
  )
}

const Panel: React.FC<{ title: string; children: React.ReactNode }> = ({ title, children }) => (
  <div style={{ background: 'var(--ap-color-bg-container)', border: '1px solid var(--ap-color-border-secondary)', borderRadius: 8, padding: 16 }}>
    <h5 style={{ margin: '0 0 12px', fontSize: 13, fontWeight: 600, color: 'var(--ap-color-text)' }}>{title}</h5>
    {children}
  </div>
)

const retryBtnStyle: React.CSSProperties = {
  padding: '6px 16px',
  border: '1px solid var(--ap-color-border)',
  borderRadius: 6,
  background: 'var(--ap-color-bg-container)',
  cursor: 'pointer',
  fontSize: 13,
}
