import React, { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import type { ExplainResponse, RuleEvaluation, ExplainOperation } from '../../types'
import { OpenButton } from '../shared/open-button'
import { ExpandablePath } from '../shared/expandable-path'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'
import { modalOverlayStyle, modalSurfaceStyle } from '../shared/modal-styles'

interface ExplainModalProps {
  data: ExplainResponse
  onClose: () => void
}

type GroupMode = 'asset' | 'rule'

const reasonLabels: Record<string, string> = {
  disabled: 'asset-pilot.explain.disabled',
  class_mismatch: 'asset-pilot.explain.class-mismatch',
  field_mismatch: 'asset-pilot.explain.field-mismatch',
  condition_failed: 'asset-pilot.explain.condition-failed',
  filter_rejected: 'asset-pilot.explain.filter-rejected',
}

export const ExplainModal: React.FC<ExplainModalProps> = ({ data, onClose }) => {
  const { t } = useTranslation()
  const [groupMode, setGroupMode] = useState<GroupMode>('asset')
  const modalRef = useModalDismiss<HTMLDivElement>(onClose)

  const matched = data.evaluations.filter(e => e.matched).length
  const skipped = data.evaluations.filter(e => !e.matched).length

  const groupedByAsset = useMemo(() => {
    const map = new Map<string, RuleEvaluation[]>()
    for (const ev of data.evaluations) {
      const key = `${ev.assetId}|${ev.fieldName}|${ev.locale ?? ''}`
      const list = map.get(key) ?? []
      list.push(ev)
      map.set(key, list)
    }
    return map
  }, [data.evaluations])

  const groupedByRule = useMemo(() => {
    const map = new Map<string, RuleEvaluation[]>()
    for (const ev of data.evaluations) {
      const list = map.get(ev.ruleName) ?? []
      list.push(ev)
      map.set(ev.ruleName, list)
    }
    return map
  }, [data.evaluations])

  const operationsByAsset = useMemo(() => {
    const map = new Map<number, ExplainOperation>()
    for (const op of data.operations) {
      map.set(op.assetId, op)
    }
    return map
  }, [data.operations])

  return (
    <div role="presentation" style={modalOverlayStyle} onClick={event => { if (event.target === event.currentTarget) onClose() }}>
      <div ref={modalRef} role="dialog" aria-modal="true" aria-label={t('asset-pilot.explain.title', { id: data.objectId })} tabIndex={-1} style={modalStyle}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 600, color: 'var(--ap-color-text)' }}>
            {t('asset-pilot.explain.title', { id: data.objectId })}
          </h3>
          <button onClick={onClose} style={closeBtnStyle}>{t('asset-pilot.explain.close')}</button>
        </div>

        <div style={{ display: 'flex', gap: 12, marginBottom: 16, flexWrap: 'wrap', alignItems: 'center' }}>
          <span style={summaryBadge('var(--ap-color-success-bg)', 'var(--ap-color-success)')}>
            {matched} {t('asset-pilot.explain.matched')}
          </span>
          <span style={summaryBadge('var(--ap-color-fill-secondary)', 'var(--ap-color-text-secondary)')}>
            {skipped} {t('asset-pilot.explain.skipped')}
          </span>
          {data.operations.length > 0 && (
            <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-primary)', fontWeight: 500 }}>
              {t('asset-pilot.explain.operations-preview', { count: data.operations.length })}
            </span>
          )}

          <div style={{ marginLeft: 'auto', display: 'flex', gap: 4 }}>
            <button
              onClick={() => setGroupMode('asset')}
              style={groupMode === 'asset' ? activeGroupBtn : groupBtn}
            >
              {t('asset-pilot.explain.group-by-asset')}
            </button>
            <button
              onClick={() => setGroupMode('rule')}
              style={groupMode === 'rule' ? activeGroupBtn : groupBtn}
            >
              {t('asset-pilot.explain.group-by-rule')}
            </button>
          </div>
        </div>

        <div style={{ maxHeight: '60vh', overflowY: 'auto' }}>
          {data.evaluations.length === 0 ? (
            <p style={{ color: 'var(--ap-color-text-secondary)', fontSize: 13, textAlign: 'center', padding: 24 }}>
              {t('asset-pilot.explain.no-evaluations')}
            </p>
          ) : groupMode === 'asset' ? (
            <AssetGroupView groups={groupedByAsset} operations={operationsByAsset} t={t} />
          ) : (
            <RuleGroupView groups={groupedByRule} t={t} />
          )}
        </div>
      </div>
    </div>
  )
}

const AssetGroupView: React.FC<{
  groups: Map<string, RuleEvaluation[]>
  operations: Map<number, ExplainOperation>
  t: (key: string, opts?: Record<string, unknown>) => string
}> = ({ groups, operations, t }) => (
  <>
    {[...groups.entries()].map(([key, evals]) => {
      const first = evals[0]
      const op = operations.get(first.assetId)
      return (
        <div key={key} style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 6, flexWrap: 'wrap' }}>
            <OpenButton id={first.assetId} type="asset" />
            <ExpandablePath path={first.assetPath} maxLength={50} />
            <span style={fieldBadge}>{first.fieldName}{first.locale ? ` (${first.locale})` : ''}</span>
            {op != null && (
              <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-primary)', fontFamily: 'monospace' }}>
                → {op.targetPath}
              </span>
            )}
          </div>
          <EvalTable evals={evals} t={t} />
        </div>
      )
    })}
  </>
)

const RuleGroupView: React.FC<{
  groups: Map<string, RuleEvaluation[]>
  t: (key: string, opts?: Record<string, unknown>) => string
}> = ({ groups, t }) => (
  <>
    {[...groups.entries()].map(([ruleName, evals]) => {
      const matchedCount = evals.filter(e => e.matched).length
      return (
        <div key={ruleName} style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 6 }}>
            <span style={{ fontWeight: 600, fontSize: 13, color: 'var(--ap-color-text)' }}>{ruleName}</span>
            <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>
              (p{evals[0].priority}, {t(evals[0].enabled ? 'asset-pilot.explain.enabled' : 'asset-pilot.explain.disabled')})
            </span>
            <span style={summaryBadge('var(--ap-color-success-bg)', 'var(--ap-color-success)')}>
              {matchedCount}/{evals.length}
            </span>
          </div>
          <table style={tableStyle}>
            <thead>
              <tr style={headerRow}>
                <th style={thStyle}>{t('asset-pilot.explain.asset')}</th>
                <th style={thStyle}>{t('asset-pilot.explain.field')}</th>
                <th style={thStyle}>{t('asset-pilot.explain.result')}</th>
                <th style={thStyle}>{t('asset-pilot.explain.reason')}</th>
                <th style={thStyle}>{t('asset-pilot.explain.target')}</th>
              </tr>
            </thead>
            <tbody>
              {evals.map((ev, i) => (
                <tr key={i} style={rowStyle}>
                  <td style={tdStyle}><OpenButton id={ev.assetId} type="asset" /></td>
                  <td style={tdStyle}>
                    <span style={fieldBadge}>{ev.fieldName}{ev.locale ? ` (${ev.locale})` : ''}</span>
                  </td>
                  <td style={tdStyle}><ResultBadge matched={ev.matched} t={t} /></td>
                  <td style={tdStyle}><ReasonCell ev={ev} t={t} /></td>
                  <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }}>
                    {ev.resolvedPath ?? '-'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )
    })}
  </>
)

const EvalTable: React.FC<{
  evals: RuleEvaluation[]
  t: (key: string, opts?: Record<string, unknown>) => string
}> = ({ evals, t }) => (
  <table style={tableStyle}>
    <thead>
      <tr style={headerRow}>
        <th style={thStyle}>{t('asset-pilot.explain.rule')}</th>
        <th style={{ ...thStyle, width: 50, textAlign: 'center' }}>{t('asset-pilot.columns.priority-short')}</th>
        <th style={thStyle}>{t('asset-pilot.explain.result')}</th>
        <th style={thStyle}>{t('asset-pilot.explain.reason')}</th>
        <th style={thStyle}>{t('asset-pilot.explain.target')}</th>
      </tr>
    </thead>
    <tbody>
      {evals.map((ev, i) => (
        <tr key={i} style={rowStyle}>
          <td style={{ ...tdStyle, fontWeight: 500 }}>{ev.ruleName}</td>
          <td style={{ ...tdStyle, textAlign: 'center', color: 'var(--ap-color-text-secondary)' }}>{ev.priority}</td>
          <td style={tdStyle}><ResultBadge matched={ev.matched} t={t} /></td>
          <td style={tdStyle}><ReasonCell ev={ev} t={t} /></td>
          <td style={{ ...tdStyle, fontFamily: 'monospace', fontSize: 'var(--ap-font-size)' }}>
            {ev.resolvedPath ?? '-'}
          </td>
        </tr>
      ))}
    </tbody>
  </table>
)

const ResultBadge: React.FC<{ matched: boolean; t: (key: string) => string }> = ({ matched, t }) => (
  <span style={{
    display: 'inline-block',
    padding: '1px 8px',
    borderRadius: 4,
    fontSize: 'var(--ap-font-size)',
    fontWeight: 600,
    background: matched ? 'var(--ap-color-success-bg)' : 'var(--ap-color-fill-secondary)',
    color: matched ? 'var(--ap-color-success)' : 'var(--ap-color-text-secondary)',
  }}>
    {matched ? t('asset-pilot.explain.matched') : t('asset-pilot.explain.skipped')}
  </span>
)

const ReasonCell: React.FC<{
  ev: RuleEvaluation
  t: (key: string) => string
}> = ({ ev, t }) => {
  if (ev.matched) return <span style={{ color: 'var(--ap-color-success-text)', fontSize: 'var(--ap-font-size)' }}>—</span>

  const reasonKey = ev.rejectionReason != null ? reasonLabels[ev.rejectionReason] : undefined
  const reasonText = reasonKey != null ? t(reasonKey) : (ev.rejectionReason ?? '—')

  return (
    <div style={{ fontSize: 'var(--ap-font-size)' }}>
      <span style={{ color: 'var(--ap-color-text-secondary)' }}>{reasonText}</span>
      {ev.filterDetails != null && (
        <div style={{ color: 'var(--ap-color-text-tertiary)', fontSize: 'var(--ap-font-size)', marginTop: 1 }}>{ev.filterDetails}</div>
      )}
      {ev.conditionError != null && (
        <div style={{ color: 'var(--ap-color-error-text)', fontSize: 'var(--ap-font-size)', marginTop: 1 }}>{ev.conditionError}</div>
      )}
      {ev.conditionExpression != null && ev.rejectionReason === 'condition_failed' && (
        <div style={{ color: 'var(--ap-color-text-tertiary)', fontSize: 'var(--ap-font-size)', fontFamily: 'monospace', marginTop: 1 }}>
          {ev.conditionExpression}
        </div>
      )}
    </div>
  )
}

const modalStyle: React.CSSProperties = {
  ...modalSurfaceStyle,
  width: 900, maxWidth: '95vw', maxHeight: '90vh',
  display: 'flex', flexDirection: 'column',
}
const closeBtnStyle: React.CSSProperties = {
  padding: '4px 14px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)',
  cursor: 'pointer', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)',
}
const groupBtn: React.CSSProperties = {
  padding: '3px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)',
  cursor: 'pointer', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)',
}
const activeGroupBtn: React.CSSProperties = {
  ...groupBtn, background: 'var(--ap-color-primary)', color: 'var(--ap-color-text-light-solid)', borderColor: 'var(--ap-color-primary)',
}
const tableStyle: React.CSSProperties = {
  width: '100%', borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)',
}
const headerRow: React.CSSProperties = { borderBottom: '2px solid var(--ap-color-border-secondary)' }
const rowStyle: React.CSSProperties = { borderBottom: '1px solid var(--ap-color-fill-secondary)' }
const thStyle: React.CSSProperties = {
  textAlign: 'left', padding: '5px 6px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', fontWeight: 500, whiteSpace: 'nowrap',
}
const tdStyle: React.CSSProperties = { padding: '5px 6px' }
const fieldBadge: React.CSSProperties = {
  display: 'inline-block', padding: '0 6px', borderRadius: 3, fontSize: 'var(--ap-font-size)',
  background: 'var(--ap-color-info-bg)', color: 'var(--ap-color-primary)', fontFamily: 'monospace',
}
const summaryBadge = (bg: string, color: string): React.CSSProperties => ({
  display: 'inline-block', padding: '2px 10px', borderRadius: 4, fontSize: 'var(--ap-font-size)',
  fontWeight: 600, background: bg, color,
})
