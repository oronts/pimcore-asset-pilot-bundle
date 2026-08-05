import React, { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { OperationRun, OperationRunSummary } from '../../types'
import { useToast } from '../../hooks/use-toast'
import { formatDate } from '../../utils/format'
import { visuallyHiddenStyle } from '../shared/visually-hidden-style'

const HISTORY_LIMIT = 20

interface SimulationMove {
  key: string
  from: string
  to: string
  ruleName: string
}

/** Read the from/to diff a simulation recorded in each item's state (see OperationsController::simulate). */
function readMoves(run: OperationRun): SimulationMove[] {
  return run.items.map(item => ({
    key: item.key,
    from: typeof item.state.from === 'string' ? item.state.from : '',
    to: typeof item.state.to === 'string' ? item.state.to : '',
    ruleName: typeof item.state.ruleName === 'string' ? item.state.ruleName : '',
  }))
}

/**
 * P3: run a dry-run for an object and persist it as a durable "simulation" run (no mutation), then browse
 * recorded simulations and view their asset move diff. Recording is View-gated server-side.
 */
export const SimulationsPanel: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const titleId = useId()
  const inputId = useId()
  const [objectId, setObjectId] = useState('')
  const [simulating, setSimulating] = useState(false)
  const [simulations, setSimulations] = useState<OperationRunSummary[] | null>(null)
  const [selected, setSelected] = useState<{ id: string, moves: SimulationMove[] } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const request = useRef<AbortController | null>(null)
  const requestId = useRef(0)
  const diffRequest = useRef<AbortController | null>(null)
  const diffRequestId = useRef(0)

  const fetchSimulations = useCallback(async (): Promise<void> => {
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    const id = ++requestId.current
    try {
      const response = await assetPilotApi.getSimulations(HISTORY_LIMIT, controller.signal)
      if (id === requestId.current) {
        setSimulations(response.items)
        setError(null)
      }
    } catch (loadError) {
      if (id === requestId.current && !(loadError instanceof Error && loadError.name === 'AbortError')) {
        setError(loadError instanceof Error ? loadError.message : t('asset-pilot.common.unknown-error'))
      }
    }
  }, [t])

  useEffect(() => {
    void fetchSimulations()
    return () => {
      request.current?.abort()
      diffRequest.current?.abort()
    }
  }, [fetchSimulations])

  const parsedObjectId = Number.parseInt(objectId.trim(), 10)
  const canSimulate = Number.isInteger(parsedObjectId) && parsedObjectId > 0

  const runSimulation = useCallback(async (): Promise<void> => {
    const id = Number.parseInt(objectId.trim(), 10)
    if (!Number.isInteger(id) || id <= 0) return
    setSimulating(true)
    try {
      const result = await assetPilotApi.simulate(id)
      if (result.runId == null) {
        toast.warning(t('asset-pilot.simulations.no-moves', { id }))
      } else {
        toast.success(t('asset-pilot.simulations.recorded', { count: result.operations.length }))
      }
      await fetchSimulations()
    } catch (simulateError) {
      toast.error(simulateError instanceof Error ? simulateError.message : t('asset-pilot.common.unknown-error'))
    } finally {
      setSimulating(false)
    }
  }, [objectId, toast, t, fetchSimulations])

  const viewDiff = useCallback(async (runId: string): Promise<void> => {
    diffRequest.current?.abort()
    const controller = new AbortController()
    diffRequest.current = controller
    const id = ++diffRequestId.current
    try {
      const run = await assetPilotApi.getOperationRun(runId, controller.signal)
      if (id === diffRequestId.current) {
        setSelected({ id: runId, moves: readMoves(run) })
      }
    } catch (loadError) {
      if (id === diffRequestId.current && !(loadError instanceof Error && loadError.name === 'AbortError')) {
        toast.error(loadError instanceof Error ? loadError.message : t('asset-pilot.common.unknown-error'))
      }
    }
  }, [toast, t])

  return (
    <section aria-labelledby={titleId}>
      <div>
        <h4 id={titleId} style={titleStyle}>{t('asset-pilot.simulations.title')}</h4>
        <p style={descriptionStyle}>{t('asset-pilot.simulations.description')}</p>
      </div>

      <div style={formRowStyle}>
        <label htmlFor={inputId} style={visuallyHiddenStyle}>{t('asset-pilot.simulations.object-id')}</label>
        <input
          id={inputId}
          type="number"
          min={1}
          inputMode="numeric"
          value={objectId}
          onChange={event => setObjectId(event.target.value)}
          placeholder={t('asset-pilot.simulations.object-id')}
          style={inputStyle}
        />
        <button
          type="button"
          onClick={() => { void runSimulation() }}
          disabled={simulating || !canSimulate}
          style={primaryButtonStyle}
        >
          {simulating ? t('asset-pilot.simulations.simulating') : t('asset-pilot.simulations.simulate')}
        </button>
      </div>

      {error != null && (
        <p role="alert" style={errorStyle}>{t('asset-pilot.simulations.load-failed', { message: error })}</p>
      )}

      {simulations?.length === 0 && (
        <p role="status" style={mutedStyle}>{t('asset-pilot.simulations.empty')}</p>
      )}

      {simulations != null && simulations.length > 0 && (
        <div style={tableContainerStyle}>
          <table style={tableStyle}>
            <caption style={visuallyHiddenStyle}>{t('asset-pilot.simulations.table')}</caption>
            <thead>
              <tr>
                <th scope="col" style={thStyle}>{t('asset-pilot.simulations.moves')}</th>
                <th scope="col" style={thStyle}>{t('asset-pilot.simulations.recorded-at')}</th>
                <th scope="col" style={thStyle}>{t('asset-pilot.operation-history.details')}</th>
              </tr>
            </thead>
            <tbody>
              {simulations.map(simulation => {
                const isSelected = selected?.id === simulation.id
                return (
                  <tr key={simulation.id} style={isSelected ? selectedRowStyle : rowStyle}>
                    <td style={tdStyle}>{simulation.totalCount}</td>
                    <td style={tdStyle}><time dateTime={simulation.createdAt}>{formatDate(simulation.createdAt)}</time></td>
                    <td style={tdStyle}>
                      <button
                        type="button"
                        aria-label={t('asset-pilot.simulations.open-label', { id: simulation.id })}
                        aria-pressed={isSelected}
                        onClick={() => { void viewDiff(simulation.id) }}
                        style={secondaryButtonStyle}
                      >
                        {t('asset-pilot.simulations.view-diff')}
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {selected != null && (
        <div style={tableContainerStyle}>
          <table style={tableStyle}>
            <caption style={visuallyHiddenStyle}>{t('asset-pilot.simulations.diff-table')}</caption>
            <thead>
              <tr>
                <th scope="col" style={thStyle}>{t('asset-pilot.simulations.from')}</th>
                <th scope="col" style={thStyle}>{t('asset-pilot.simulations.to')}</th>
                <th scope="col" style={thStyle}>{t('asset-pilot.simulations.rule')}</th>
              </tr>
            </thead>
            <tbody>
              {selected.moves.map(move => (
                <tr key={move.key} style={rowStyle}>
                  <td style={tdStyle}><code style={pathStyle}>{move.from}</code></td>
                  <td style={tdStyle}><code style={pathStyle}>{move.to}</code></td>
                  <td style={tdStyle}>{move.ruleName}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

const titleStyle: React.CSSProperties = { margin: 0, fontSize: 14, fontWeight: 600 }
const descriptionStyle: React.CSSProperties = { margin: '4px 0 0', color: 'var(--ap-color-text-secondary)', fontSize: 'var(--ap-font-size)' }
const formRowStyle: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, marginTop: 12, flexWrap: 'wrap' }
const inputStyle: React.CSSProperties = { padding: '6px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 5, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-text)', fontSize: 'var(--ap-font-size)', width: 160 }
const primaryButtonStyle: React.CSSProperties = { padding: '6px 14px', border: 'none', borderRadius: 5, background: 'var(--ap-color-primary)', color: 'var(--ap-color-white, #fff)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 600 }
const secondaryButtonStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid var(--ap-color-border)', borderRadius: 5, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-text-secondary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', whiteSpace: 'nowrap' }
const mutedStyle: React.CSSProperties = { color: 'var(--ap-color-text-secondary)', fontSize: 'var(--ap-font-size)', marginTop: 10 }
const errorStyle: React.CSSProperties = { color: 'var(--ap-color-error-text-active)', fontSize: 'var(--ap-font-size)', margin: '10px 0 0' }
const tableContainerStyle: React.CSSProperties = { overflowX: 'auto', marginTop: 12 }
const tableStyle: React.CSSProperties = { width: '100%', borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)' }
const rowStyle: React.CSSProperties = { borderTop: '1px solid var(--ap-color-border-secondary)' }
const selectedRowStyle: React.CSSProperties = { ...rowStyle, background: 'var(--ap-color-primary-bg)' }
const thStyle: React.CSSProperties = { textAlign: 'left', color: 'var(--ap-color-text-secondary)', fontSize: 'var(--ap-font-size)', fontWeight: 600, padding: '8px 6px' }
const tdStyle: React.CSSProperties = { padding: '8px 6px', verticalAlign: 'middle' }
const pathStyle: React.CSSProperties = { color: 'var(--ap-color-text)', fontSize: 'var(--ap-font-size)' }
