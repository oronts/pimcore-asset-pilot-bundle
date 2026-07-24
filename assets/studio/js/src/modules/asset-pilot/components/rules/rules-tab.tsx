import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useRules } from '../../hooks/use-asset-pilot-api'
import type { RuleData } from '../../types'
import { assetPilotApi } from '../../services/api'
import { useToast } from '../../hooks/use-toast'
import { StrategyTag } from '../shared/status-tag'
import { RuleDetailModal } from './rule-detail-modal'
import { RulePreviewModal } from './rule-preview-modal'
import { RuleOverlapPanel } from './rule-overlap-panel'
import { RuleDiffModal } from './rule-diff-modal'
import { TableSkeleton } from '../shared/skeleton/table-skeleton'
import { EmptyState } from '../shared/empty-state'
import { ResponsiveTableWrapper } from '../shared/responsive-table-wrapper'
import { SortableHeader } from '../shared/sortable-header'
import { useSort } from '../../hooks/use-sort'
import { useContainerWidth } from '../../hooks/use-container-width'
import { getVisibleColumns, type ColumnConfig } from '../../utils/column-visibility'

const cols: ColumnConfig[] = [
  { key: 'expand', priority: 1 },
  { key: 'name', priority: 1 },
  { key: 'class', priority: 1 },
  { key: 'strategy', priority: 2 },
  { key: 'targetPath', priority: 2 },
  { key: 'priority', priority: 3 },
  { key: 'enabled', priority: 3 },
  { key: 'actions', priority: 1 },
]

export const RulesTab: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { data: rules, loading, error, refetch } = useRules()
  const [exporting, setExporting] = useState(false)
  const [detailRule, setDetailRule] = useState<string | null>(null)
  const [previewRule, setPreviewRule] = useState<string | null>(null)
  const [comparing, setComparing] = useState(false)
  const [expandedRows, setExpandedRows] = useState<Set<string>>(new Set())
  const { sortField, sortDirection, toggleSort, sortedData } = useSort<RuleData>()
  const [containerRef, containerWidth] = useContainerWidth()
  const visible = getVisibleColumns(cols, containerWidth)

  if (loading) return <TableSkeleton rows={3} columns={8} />
  if (error != null) return (
    <div>
      <p role="alert" style={{ color: 'var(--ap-color-error-text-active)', fontSize: 13 }}>{t('asset-pilot.common.error', { message: error })}</p>
      <button onClick={refetch} style={btnStyle}>{t('asset-pilot.common.retry')}</button>
    </div>
  )

  const toggleRow = (name: string): void => {
    setExpandedRows(prev => {
      const next = new Set(prev)
      if (next.has(name)) next.delete(name)
      else next.add(name)
      return next
    })
  }

  const handleExport = async (): Promise<void> => {
    setExporting(true)
    try {
      await assetPilotApi.exportRules()
    } catch {
      toast.error(t('asset-pilot.rules.export.failed'))
    } finally {
      setExporting(false)
    }
  }

  const sorted = sortedData(rules ?? [])
  const hasRules = sorted.length > 0

  return (
    <div ref={containerRef}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.rules.configured', { count: sorted.length })}</h4>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={() => setComparing(true)} style={btnStyle}>{t('asset-pilot.rules.diff.button')}</button>
          <button onClick={handleExport} disabled={exporting} style={btnStyle}>
            {exporting ? t('asset-pilot.common.loading') : t('asset-pilot.rules.export.button')}
          </button>
        </div>
      </div>

      {hasRules && <RuleOverlapPanel />}

      {!hasRules ? (
        <EmptyState variant="no-data" title={t('asset-pilot.empty.no-rules')} description={t('asset-pilot.empty.no-data-desc')} />
      ) : (
      <ResponsiveTableWrapper label={t('asset-pilot.common.table-scroll-region')}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ borderBottom: '2px solid var(--ap-color-border-secondary)' }}>
              {visible.has('expand') && <th style={thStyle}></th>}
              {visible.has('name') && <SortableHeader label={t('asset-pilot.columns.name')} field="name" currentField={sortField} direction={sortDirection} onToggle={toggleSort} style={{ padding: '8px 6px' }} />}
              {visible.has('class') && <SortableHeader label={t('asset-pilot.columns.class')} field="class" currentField={sortField} direction={sortDirection} onToggle={toggleSort} style={{ padding: '8px 6px' }} />}
              {visible.has('strategy') && <th style={thStyle}>{t('asset-pilot.columns.strategy')}</th>}
              {visible.has('targetPath') && <th style={thStyle}>{t('asset-pilot.columns.target-path')}</th>}
              {visible.has('priority') && <SortableHeader label={t('asset-pilot.columns.priority')} field="priority" currentField={sortField} direction={sortDirection} onToggle={toggleSort} style={{ padding: '8px 6px', textAlign: 'center' }} />}
              {visible.has('enabled') && <SortableHeader label={t('asset-pilot.columns.enabled')} field="enabled" currentField={sortField} direction={sortDirection} onToggle={toggleSort} style={{ padding: '8px 6px', textAlign: 'center' }} />}
              {visible.has('actions') && <th style={thStyle}>{t('asset-pilot.common.actions')}</th>}
            </tr>
          </thead>
          <tbody>
            {sorted.map(rule => (
              <React.Fragment key={rule.name}>
                <tr style={{ borderBottom: '1px solid var(--ap-color-fill-secondary)' }}>
                  {visible.has('expand') && (
                    <td style={tdStyle}>
                      <button
                        onClick={() => toggleRow(rule.name)}
                        aria-expanded={expandedRows.has(rule.name)}
                        aria-controls={`asset-pilot-rule-${encodeURIComponent(rule.name)}`}
                        aria-label={t(expandedRows.has(rule.name) ? 'asset-pilot.rules.collapse-row' : 'asset-pilot.rules.expand-row', { name: rule.name })}
                        style={{ border: 'none', background: 'none', cursor: 'pointer', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}
                      >
                        {expandedRows.has(rule.name) ? '\u25BC' : '\u25B6'}
                      </button>
                    </td>
                  )}
                  {visible.has('name') && <td style={{ ...tdStyle, fontWeight: 600 }}>{rule.name}</td>}
                  {visible.has('class') && <td style={tdStyle}>{rule.class}</td>}
                  {visible.has('strategy') && <td style={tdStyle}><StrategyTag strategy={rule.strategy} /></td>}
                  {visible.has('targetPath') && <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }}>{rule.targetPath}</td>}
                  {visible.has('priority') && <td style={{ ...tdStyle, textAlign: 'center' }}>{rule.priority}</td>}
                  {visible.has('enabled') && (
                    <td style={{ ...tdStyle, textAlign: 'center' }}>
                      <span style={{
                        background: rule.enabled ? 'var(--ap-color-success-bg)' : 'var(--ap-color-error-bg)',
                        color: rule.enabled ? 'var(--ap-color-success-text)' : 'var(--ap-color-error-text)',
                        padding: '1px 8px', borderRadius: 4, fontSize: 'var(--ap-font-size)', fontWeight: 500,
                      }}>
                        {rule.enabled ? t('asset-pilot.common.yes') : t('asset-pilot.common.no')}
                      </span>
                    </td>
                  )}
                  {visible.has('actions') && (
                    <td style={tdStyle}>
                      <div style={{ display: 'flex', gap: 4 }}>
                        <button onClick={() => setDetailRule(rule.name)} style={actionBtnStyle}>{t('asset-pilot.rules.detail')}</button>
                        <button onClick={() => setPreviewRule(rule.name)} style={actionBtnStyle}>{t('asset-pilot.rules.preview')}</button>
                      </div>
                    </td>
                  )}
                </tr>
                {expandedRows.has(rule.name) && (
                  <tr id={`asset-pilot-rule-${encodeURIComponent(rule.name)}`}>
                    <td colSpan={8} style={{ padding: '8px 24px 16px', background: 'var(--ap-color-fill-alter)' }}>
                      <ExpandedRuleDetails rule={rule} />
                    </td>
                  </tr>
                )}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      </ResponsiveTableWrapper>
      )}

      {detailRule != null && <RuleDetailModal ruleName={detailRule} onClose={() => setDetailRule(null)} />}
      {previewRule != null && <RulePreviewModal ruleName={previewRule} onClose={() => setPreviewRule(null)} />}
      {comparing && <RuleDiffModal onClose={() => setComparing(false)} />}
    </div>
  )
}

const ExpandedRuleDetails: React.FC<{ rule: RuleData }> = ({ rule }) => {
  const { t } = useTranslation()
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 16, fontSize: 'var(--ap-font-size)' }}>
      <div>
        <strong style={{ color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.rules.fields')}</strong>
        <p style={{ margin: '4px 0 0' }}>{rule.fields?.join(', ') || t('asset-pilot.rules.all')}</p>
      </div>
      {rule.condition != null && (
        <div>
          <strong style={{ color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.rules.condition')}</strong>
          <p style={{ margin: '4px 0 0', fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }}>{rule.condition}</p>
        </div>
      )}
      {rule.filters != null && Object.keys(rule.filters).length > 0 && (
        <div>
          <strong style={{ color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.rules.filters')}</strong>
          {Object.entries(rule.filters).map(([key, val]) => (
            <p key={key} style={{ margin: '4px 0 0' }}>
              {key}: <code style={{ fontSize: 'var(--ap-font-size)' }}>{JSON.stringify(val)}</code>
            </p>
          ))}
        </div>
      )}
    </div>
  )
}

const thStyle: React.CSSProperties = { textAlign: 'left', padding: '8px 6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '8px 6px' }
const btnStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)', cursor: 'pointer', fontSize: 13 }
const actionBtnStyle: React.CSSProperties = {
  padding: '3px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)',
  cursor: 'pointer', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-primary)',
}
