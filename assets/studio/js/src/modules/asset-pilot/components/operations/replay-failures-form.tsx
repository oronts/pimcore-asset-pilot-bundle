import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { MoveOperation } from '../../types'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'
import { useReviewedOperation, requireOperations } from '../../hooks/use-reviewed-operation'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { OperationRunPanel } from './operation-run-panel'

interface ReviewedReplay {
  since?: string
  rule?: string
  className?: string
  limit: number
  async: boolean
  planToken: string | null
  objectCount: number
  operations: MoveOperation[]
}

export const ReplayFailuresForm: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const [queueAsync, setQueueAsync] = useState(true)
  const [since, setSince] = useState('')
  const [rule, setRule] = useState('')
  const [className, setClassName] = useState('')
  const [limit, setLimit] = useState('100')
  const op = useReviewedOperation<ReviewedReplay>({ messageNamespace: 'asset-pilot.operations', fallbackErrorKey: 'asset-pilot.replay.failed', validatePreview: requireOperations })

  if (!operate) {
    return null
  }

  const handleReview = async (): Promise<void> => {
    const reviewedLimit = Math.max(1, Math.min(1000, Number.parseInt(limit, 10) || 100))
    const selection = {
      async: queueAsync,
      since: toUtcInstant(since),
      rule: rule || undefined,
      class: className || undefined,
      limit: reviewedLimit,
    }
    await op.review(
      signal => assetPilotApi.replayFailures({ ...selection, dryRun: true }, signal),
      result => ({
        since: selection.since,
        rule: selection.rule,
        className: selection.class,
        limit: selection.limit,
        async: selection.async,
        planToken: result.planToken,
        objectCount: result.objectCount,
        operations: result.operations ?? [],
      }),
    )
  }

  const handleApply = async (): Promise<void> => {
    await op.apply(
      (plan, signal) => assetPilotApi.replayFailures({
        async: plan.async,
        since: plan.since,
        rule: plan.rule,
        class: plan.className,
        limit: plan.limit,
        dryRun: false,
        planToken: plan.planToken,
      }, signal),
      (result, plan) => {
        if (plan.async) toast.success(t('asset-pilot.replay.queued', { count: result.dispatched }))
        else if (result.failed > 0) toast.warning(t('asset-pilot.replay.partial', { organized: result.organized, failed: result.failed }))
        else toast.success(t('asset-pilot.replay.done', { count: result.organized }))
      },
    )
  }

  return (
    <div>
      <h4 style={{ margin: '0 0 4px', fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.replay.title')}</h4>
      <p style={{ margin: '0 0 12px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.replay.desc')}</p>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
        <label style={fieldStyle}>{t('asset-pilot.replay.since')}<input type="datetime-local" value={since} disabled={op.running} onChange={event => { setSince(event.target.value); op.clear() }} style={inputStyle} /></label>
        <label style={fieldStyle}>{t('asset-pilot.replay.rule')}<input value={rule} disabled={op.running} onChange={event => { setRule(event.target.value); op.clear() }} style={inputStyle} /></label>
        <label style={fieldStyle}>{t('asset-pilot.replay.class')}<input value={className} disabled={op.running} onChange={event => { setClassName(event.target.value); op.clear() }} style={inputStyle} /></label>
        <label style={fieldStyle}>{t('asset-pilot.replay.limit')}<input type="number" min={1} max={1000} value={limit} disabled={op.running} onChange={event => { setLimit(event.target.value); op.clear() }} style={{ ...inputStyle, width: 90 }} /></label>
      </div>
      <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, marginBottom: 12 }}>
        <input type="checkbox" checked={queueAsync} disabled={op.running} onChange={event => { setQueueAsync(event.target.checked); op.clear() }} />
        {t('asset-pilot.replay.async')}
      </label>
      <button onClick={() => { void handleReview() }} disabled={op.running} style={btnStyle}>
        {op.running ? t('asset-pilot.operations.previewing') : t('asset-pilot.replay.review-button')}
      </button>
      {op.runId != null && <OperationRunPanel runId={op.runId} onRunIdChange={op.setRunId} />}
      {op.confirming && (
        <ConfirmDialog
          title={t('asset-pilot.replay.confirm-title')}
          description={t('asset-pilot.replay.confirm-description', {
            objects: op.reviewedPlan?.objectCount ?? 0,
            operations: op.reviewedPlan?.operations.length ?? 0,
          })}
          confirmLabel={t('asset-pilot.replay.apply-button')}
          variant="warning"
          loading={op.running}
          confirmDisabled={op.reviewedPlan?.planToken == null}
          onConfirm={() => { void handleApply() }}
          onCancel={op.cancel}
        />
      )}
    </div>
  )
}

// The since field is a browser-local datetime-local; the backend reads an offsetless value as UTC, so
// convert the local wall-clock to an explicit UTC instant to avoid a silent timezone-sized drift.
function toUtcInstant(localValue: string): string | undefined {
  if (localValue === '') return undefined
  const parsed = new Date(localValue)
  return Number.isNaN(parsed.getTime()) ? undefined : parsed.toISOString()
}

const btnStyle: React.CSSProperties = {
  padding: '6px 16px',
  border: '1px solid var(--ap-color-border)',
  borderRadius: 6,
  background: 'var(--ap-color-bg-container)',
  cursor: 'pointer',
  fontSize: 13,
}
const fieldStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }
const inputStyle: React.CSSProperties = { padding: '5px 8px', border: '1px solid var(--ap-color-border)', borderRadius: 4, fontSize: 'var(--ap-font-size)' }
