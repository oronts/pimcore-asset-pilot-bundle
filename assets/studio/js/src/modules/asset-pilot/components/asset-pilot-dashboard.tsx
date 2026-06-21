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
  const [activeTab, setActiveTab] = useState<TabKey>('dashboard')

  return (
    <ToastProvider>
    <div style={{ height: '100%', display: 'flex', flexDirection: 'column', fontFamily: 'Inter, -apple-system, sans-serif' }}>
      <div style={{ padding: '16px 24px 0', borderBottom: '1px solid #f0f0f0' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
          <h2 style={{ margin: 0, fontSize: 18, fontWeight: 600, color: '#1a1a1a' }}>{t('asset-pilot.nav.title')}</h2>
          <span style={{
            fontSize: 11,
            color: '#8c8c8c',
            background: '#f5f5f5',
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
            style={{ marginLeft: 'auto', fontSize: 12, fontWeight: 500, color: '#1677ff', textDecoration: 'none' }}
          >
            {t('asset-pilot.nav.docs')} ↗
          </a>
        </div>

        <div style={{ display: 'flex', gap: 0 }}>
          {tabKeys.map(key => (
            <button
              key={key}
              onClick={() => setActiveTab(key)}
              style={{
                padding: '8px 16px',
                border: 'none',
                borderBottom: activeTab === key ? '2px solid #1677ff' : '2px solid transparent',
                background: 'none',
                cursor: 'pointer',
                fontSize: 13,
                fontWeight: activeTab === key ? 600 : 400,
                color: activeTab === key ? '#1677ff' : '#595959',
                transition: 'all 0.2s',
              }}
            >
              {t(tabLabelKeys[key])}
            </button>
          ))}
        </div>
      </div>

      <div style={{ flex: 1, overflow: 'auto', padding: 24 }}>
        {activeTab === 'dashboard' && <DashboardTab onNavigateToAudit={() => setActiveTab('audit')} />}
        {activeTab === 'rules' && <RulesTab />}
        {activeTab === 'operations' && <OperationsTab />}
        {activeTab === 'audit' && <AuditTab />}
        {activeTab === 'unused' && <UnusedAssetsTab />}
        {activeTab === 'duplicates' && <DuplicatesTab />}
        {activeTab === 'integrity' && <IntegrityTab />}
        {activeTab === 'quarantine' && <QuarantineTab />}
        {activeTab === 'storage' && <StorageTrendsTab />}
        {activeTab === 'folders' && <EmptyFoldersTab />}
        {activeTab === 'drift' && <DriftTab />}
        {activeTab === 'management' && <AssetManagementTab />}
      </div>
    </div>
    </ToastProvider>
  )
}
