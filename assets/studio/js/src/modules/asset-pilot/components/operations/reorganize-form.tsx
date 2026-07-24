import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { MoveOperation } from '../../types'
import { usePermissions } from '../../hooks/use-permissions'
import { useToast } from '../../hooks/use-toast'
import { useReviewedOperation, requireOperations } from '../../hooks/use-reviewed-operation'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { OperationRunPanel } from './operation-run-panel'

interface ReviewedReorganization {
  folder: string
  limit: number
  async: boolean
  planToken: string | null
  assetsScanned: number
  objectCount: number
  operations: MoveOperation[]
}

export const ReorganizeForm: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const [folder, setFolder] = useState('')
  const [limit, setLimit] = useState('100')
  const [queueAsync, setQueueAsync] = useState(true)
  const op = useReviewedOperation<ReviewedReorganization>({ messageNamespace: 'asset-pilot.operations', validatePreview: requireOperations })

  if (!operate) return null

  const review = async (): Promise<void> => {
    const normalizedFolder = folder.trim()
    if (normalizedFolder === '') return
    const normalizedLimit = Math.max(1, Number.parseInt(limit, 10) || 100)
    await op.review(
      signal => assetPilotApi.reorganize({ folder: normalizedFolder, limit: normalizedLimit, async: queueAsync, dryRun: true }, signal),
      result => ({
        folder: normalizedFolder,
        limit: normalizedLimit,
        async: queueAsync,
        planToken: result.planToken,
        assetsScanned: result.assetsScanned,
        objectCount: result.objectCount,
        operations: result.operations ?? [],
      }),
    )
  }

  const apply = async (): Promise<void> => {
    await op.apply(
      (plan, signal) => assetPilotApi.reorganize({ folder: plan.folder, limit: plan.limit, async: plan.async, dryRun: false, planToken: plan.planToken }, signal),
      result => {
        const message = t('asset-pilot.reorganize.result', {
          scanned: result.assetsScanned,
          organized: result.organized,
          dispatched: result.dispatched,
          failed: result.failed,
        })
        if (result.failed > 0) toast.warning(message)
        else toast.success(message)
      },
    )
  }

  return (
    <div>
      <h4 style={{ margin: '0 0 4px', fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.reorganize.title')}</h4>
      <p style={{ margin: '0 0 12px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.reorganize.description')}</p>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 8, flexWrap: 'wrap' }}>
        <label style={fieldStyle}>{t('asset-pilot.reorganize.folder')}<input value={folder} disabled={op.running} onChange={event => { setFolder(event.target.value); op.clear() }} placeholder={t('asset-pilot.reorganize.folder-placeholder')} style={inputStyle} /></label>
        <label style={fieldStyle}>{t('asset-pilot.replay.limit')}<input type="number" min={1} value={limit} disabled={op.running} onChange={event => { setLimit(event.target.value); op.clear() }} style={{ ...inputStyle, width: 90 }} /></label>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 'var(--ap-font-size)' }}><input type="checkbox" checked={queueAsync} disabled={op.running} onChange={event => { setQueueAsync(event.target.checked); op.clear() }} />{t('asset-pilot.operations.async')}</label>
        <button onClick={() => { void review() }} disabled={op.running || folder.trim() === ''} style={buttonStyle}>{op.running ? t('asset-pilot.operations.previewing') : t('asset-pilot.reorganize.review-button')}</button>
      </div>
      {op.runId != null && <OperationRunPanel runId={op.runId} onRunIdChange={op.setRunId} />}
      {op.confirming && (
        <ConfirmDialog
          title={t('asset-pilot.reorganize.confirm-title')}
          description={t('asset-pilot.reorganize.confirm-description', {
            folder: op.reviewedPlan?.folder ?? folder.trim(),
            assets: op.reviewedPlan?.assetsScanned ?? 0,
            objects: op.reviewedPlan?.objectCount ?? 0,
            operations: op.reviewedPlan?.operations.length ?? 0,
          })}
          confirmLabel={t('asset-pilot.reorganize.apply-button')}
          variant="warning"
          loading={op.running}
          confirmDisabled={op.reviewedPlan?.planToken == null}
          onConfirm={() => { void apply() }}
          onCancel={op.cancel}
        />
      )}
    </div>
  )
}

const fieldStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }
const inputStyle: React.CSSProperties = { padding: '5px 8px', border: '1px solid var(--ap-color-border)', borderRadius: 4, fontSize: 'var(--ap-font-size)' }
const buttonStyle: React.CSSProperties = { padding: '6px 16px', border: '1px solid var(--ap-color-warning)', borderRadius: 6, background: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500 }
