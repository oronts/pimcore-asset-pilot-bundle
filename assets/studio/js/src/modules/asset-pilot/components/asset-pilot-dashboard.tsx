import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ToastProvider } from './shared/toast/toast-context'
import { DashboardTab } from './dashboard/dashboard-tab'
import { RulesTab } from './rules/rules-tab'
import { OperationsTab } from './operations/operations-tab'
import { AuditTab } from './audit/audit-tab'
import { UnusedAssetsTab } from './unused-assets/unused-assets-tab'
import { DuplicatesTab } from './duplicates/duplicates-tab'
import { IntegrityTab } from './integrity/integrity-tab'
import { QuarantineTab } from './quarantine/quarantine-tab'
import { StorageTrendsTab } from './storage/storage-trends-tab'
import { EmptyFoldersTab } from './folders/empty-folders-tab'
import { DriftTab } from './drift/drift-tab'
import { AssetManagementTab } from './asset-management/asset-management-tab'
import { theme } from 'antd'
import { assetPilotThemeVariables } from './shared/theme-variables'
import { usePermissions } from '../hooks/use-permissions'

const DOCS_URL = 'https://github.com/oronts/pimcore-asset-pilot-bundle/tree/main/docs'

const tabKeys = ['dashboard', 'rules', 'operations', 'audit', 'unused', 'duplicates', 'integrity', 'quarantine', 'storage', 'folders', 'drift', 'management'] as const
type TabKey = typeof tabKeys[number]

const tabLabelKeys: Record<TabKey, string> = {
  dashboard: 'asset-pilot.tabs.dashboard',
  rules: 'asset-pilot.tabs.rules',
  operations: 'asset-pilot.tabs.operations',
  audit: 'asset-pilot.tabs.audit',
  unused: 'asset-pilot.tabs.unused',
  duplicates: 'asset-pilot.tabs.duplicates',
  integrity: 'asset-pilot.tabs.integrity',
  quarantine: 'asset-pilot.tabs.quarantine',
  storage: 'asset-pilot.tabs.storage',
  folders: 'asset-pilot.tabs.folders',
  drift: 'asset-pilot.tabs.drift',
  management: 'asset-pilot.tabs.management',
}

export const AssetPilotDashboard: React.FC = () => {
  const { t } = useTranslation()
  const { token } = theme.useToken()
  const { admin } = usePermissions()
  const [activeTab, setActiveTab] = useState<TabKey>('dashboard')
  // Storage trends read an admin-only endpoint; hide the tab from non-admins so it is neither shown nor fetched.
  const visibleTabKeys = tabKeys.filter(key => key !== 'storage' || admin)

  const moveTabFocus = (event: React.KeyboardEvent<HTMLButtonElement>, current: number): void => {
    const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End']
    if (!keys.includes(event.key)) return
    event.preventDefault()
    const next = event.key === 'Home'
      ? 0
      : event.key === 'End'
        ? visibleTabKeys.length - 1
        : (current + (event.key === 'ArrowRight' ? 1 : -1) + visibleTabKeys.length) % visibleTabKeys.length
    setActiveTab(visibleTabKeys[next])
    event.currentTarget.parentElement?.querySelectorAll<HTMLButtonElement>('[role="tab"]')[next]?.focus()
  }

  return (
    <ToastProvider>
    <div data-testid="asset-pilot-root" style={{ ...assetPilotThemeVariables(token), height: '100%', display: 'flex', flexDirection: 'column', fontFamily: 'Inter, -apple-system, sans-serif' }}>
      <div style={{ padding: '16px 24px 0', borderBottom: '1px solid var(--ap-color-border-secondary)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
          <h2 style={{ margin: 0, fontSize: 18, fontWeight: 600, color: 'var(--ap-color-text)' }}>{t('asset-pilot.nav.title')}</h2>
          <span style={{
            fontSize: 'var(--ap-font-size)',
            color: 'var(--ap-color-text-secondary)',
            background: 'var(--ap-color-fill-secondary)',
            padding: '2px 8px',
            borderRadius: 4,
            fontWeight: 500,
          }}>
            {t('asset-pilot.nav.by-oronts')}
          </span>
          <a
            href={DOCS_URL}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={t('asset-pilot.nav.docs-aria')}
            style={{ marginLeft: 'auto', fontSize: 'var(--ap-font-size)', fontWeight: 500, color: 'var(--ap-color-primary)', textDecoration: 'none' }}
          >
            {t('asset-pilot.nav.docs')} ↗
          </a>
        </div>

        <div role="tablist" aria-label={t('asset-pilot.nav.title')} style={{ display: 'flex', gap: 0, overflowX: 'auto', scrollbarWidth: 'thin' }}>
          {visibleTabKeys.map((key, index) => (
            <button
              key={key}
              id={`asset-pilot-tab-${key}`}
              role="tab"
              aria-selected={activeTab === key}
              aria-controls="asset-pilot-tabpanel"
              tabIndex={activeTab === key ? 0 : -1}
              onClick={() => setActiveTab(key)}
              onKeyDown={event => moveTabFocus(event, index)}
              style={{
                padding: '8px 16px',
                border: 'none',
                borderBottom: activeTab === key ? '2px solid var(--ap-color-primary)' : '2px solid transparent',
                background: 'none',
                cursor: 'pointer',
                fontSize: 13,
                fontWeight: activeTab === key ? 600 : 400,
                color: activeTab === key ? 'var(--ap-color-primary)' : 'var(--ap-color-text-secondary)',
                whiteSpace: 'nowrap',
                flexShrink: 0,
              }}
            >
              {t(tabLabelKeys[key])}
            </button>
          ))}
        </div>
      </div>

      <div id="asset-pilot-tabpanel" role="tabpanel" aria-labelledby={`asset-pilot-tab-${activeTab}`} tabIndex={0} style={{ flex: 1, overflow: 'auto', padding: 24 }}>
        {activeTab === 'dashboard' && <DashboardTab onNavigateToAudit={() => setActiveTab('audit')} />}
        {activeTab === 'rules' && <RulesTab />}
        {activeTab === 'operations' && <OperationsTab />}
        {activeTab === 'audit' && <AuditTab />}
        {activeTab === 'unused' && <UnusedAssetsTab />}
        {activeTab === 'duplicates' && <DuplicatesTab />}
        {activeTab === 'integrity' && <IntegrityTab />}
        {activeTab === 'quarantine' && <QuarantineTab />}
        {activeTab === 'storage' && admin && <StorageTrendsTab />}
        {activeTab === 'folders' && <EmptyFoldersTab />}
        {activeTab === 'drift' && <DriftTab />}
        {activeTab === 'management' && <AssetManagementTab />}
      </div>
    </div>
    </ToastProvider>
  )
}
