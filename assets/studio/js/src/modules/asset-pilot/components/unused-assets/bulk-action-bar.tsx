import React, { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import type { PlannedBulkActionResult } from '../../types'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'
import { useReviewedOperation } from '../../hooks/use-reviewed-operation'

interface BulkActionBarProps {
  count: number
  assetIds: number[]
  lockedIds?: number[]
  onActionComplete: (action: PlannedAction, result: PlannedBulkActionResult) => void
  onDeselect: () => void
  onLockDone?: () => void
}

export type PlannedAction = 'delete' | 'move' | 'quarantine'

interface ReviewedBulkPlan {
  planToken: string | null
  action: PlannedAction
  assetIds: number[]
  targetFolder?: string
  result: PlannedBulkActionResult
}

export const BulkActionBar: React.FC<BulkActionBarProps> = ({ count, assetIds, lockedIds = [], onActionComplete, onDeselect, onLockDone }) => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const [showMoveForm, setShowMoveForm] = useState(false)
  const [targetFolder, setTargetFolder] = useState('')
  const [lockLoading, setLockLoading] = useState(false)
  const op = useReviewedOperation<ReviewedBulkPlan>({ messageNamespace: 'asset-pilot.bulk' })
  const lockedSet = React.useMemo(() => new Set(lockedIds), [lockedIds])
  const mutableIds = React.useMemo(() => assetIds.filter(id => !lockedSet.has(id)), [assetIds, lockedSet])
  const protectedCount = assetIds.length - mutableIds.length
  const hasMutable = mutableIds.length > 0
  const selectionKey = [...assetIds].sort((left, right) => left - right).join(',')

  // Drop a reviewed plan whose selection no longer matches; op.clear also aborts an in-flight preview/apply.
  useEffect(() => {
    op.clear()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectionKey])

  const handleBulkLock = async (): Promise<void> => {
    setLockLoading(true)
    let success = 0
    for (const id of mutableIds) {
      try {
        await assetPilotApi.lockAsset(id)
        success++
      } catch {
      }
    }
    setLockLoading(false)
    const failed = mutableIds.length - success
    if (failed > 0) toast.warning(t('asset-pilot.lock.lock-partial', { success, failed }))
    else toast.success(t('asset-pilot.lock.lock-success', { count: success }))
    onLockDone?.()
  }

  const handleBulkUnlock = async (): Promise<void> => {
    setLockLoading(true)
    let success = 0
    for (const id of assetIds) {
      try {
        await assetPilotApi.unlockAsset(id)
        success++
      } catch {
      }
    }
    setLockLoading(false)
    const failed = assetIds.length - success
    if (failed > 0) toast.warning(t('asset-pilot.lock.unlock-partial', { success, failed }))
    else toast.success(t('asset-pilot.lock.unlock-success', { count: success }))
    onLockDone?.()
  }

  const previewAction = async (action: PlannedAction): Promise<void> => {
    const reviewedIds = [...mutableIds]
    const reviewedTarget = action === 'move' ? targetFolder.trim() : undefined
    if (reviewedIds.length === 0 || (action === 'move' && reviewedTarget === '')) return

    await op.review(
      signal => action === 'delete'
        ? assetPilotApi.previewBulkDeleteAssets(reviewedIds, signal)
        : action === 'move'
          ? assetPilotApi.previewBulkMoveAssets(reviewedIds, reviewedTarget ?? '', signal)
          : assetPilotApi.previewBulkQuarantineAssets(reviewedIds, signal),
      result => ({ planToken: result.planToken, action, assetIds: reviewedIds, targetFolder: reviewedTarget, result }),
    )
  }

  const applyReviewedAction = async (): Promise<void> => {
    await op.apply(
      (plan, signal) => plan.action === 'delete'
        ? assetPilotApi.applyBulkDeleteAssets(plan.assetIds, plan.planToken, signal)
        : plan.action === 'move'
          ? assetPilotApi.applyBulkMoveAssets(plan.assetIds, plan.targetFolder ?? '', plan.planToken, signal)
          : assetPilotApi.applyBulkQuarantineAssets(plan.assetIds, plan.planToken, signal),
      (result, plan) => { onActionComplete(plan.action, result); setShowMoveForm(false) },
    )
  }

  const isDisabled = op.running || lockLoading

  return (
    <div style={{
      display: 'flex', alignItems: 'center', gap: 12, padding: '10px 16px', marginBottom: 12,
      background: 'var(--ap-color-primary-bg)', border: '1px solid var(--ap-color-primary-border)', borderRadius: 8, flexWrap: 'wrap',
    }}>
      <span style={{ fontSize: 13, fontWeight: 500, color: 'var(--ap-color-primary-active)' }}>
        {t('asset-pilot.bulk.selected', { count })}
      </span>

      <div style={{ display: 'flex', gap: 8, flex: 1, flexWrap: 'wrap' }}>
        {!showMoveForm && operate && (
          <>
            <button onClick={() => { void previewAction('delete') }} disabled={isDisabled || !hasMutable} style={deleteBtnStyle}>
              {t('asset-pilot.bulk.review-delete')}
            </button>
            <button onClick={() => setShowMoveForm(true)} disabled={isDisabled || !hasMutable} style={moveBtnStyle}>
              {t('asset-pilot.bulk.move')}
            </button>
            <button onClick={() => { void previewAction('quarantine') }} disabled={isDisabled || !hasMutable} style={quarantineBtnStyle}>
              {t('asset-pilot.bulk.review-quarantine')}
            </button>
            <button onClick={() => { void handleBulkLock() }} disabled={isDisabled || !hasMutable} style={lockBtnStyle}>
              {lockLoading ? t('asset-pilot.lock.locking') : t('asset-pilot.lock.lock-selected')}
            </button>
            <button onClick={() => { void handleBulkUnlock() }} disabled={isDisabled} style={unlockBtnStyle}>
              {lockLoading ? t('asset-pilot.lock.unlocking') : t('asset-pilot.lock.unlock-selected')}
            </button>
            {protectedCount > 0 && (
              <span style={{ fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)', alignSelf: 'center' }}>
                {t('asset-pilot.bulk.protected-skipped', { count: protectedCount })}
              </span>
            )}
          </>
        )}

        {showMoveForm && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <input
              type="text"
              value={targetFolder}
              onChange={e => { setTargetFolder(e.target.value); op.clear() }}
              placeholder={t('asset-pilot.bulk.target-folder')}
              style={{ padding: '4px 8px', border: '1px solid var(--ap-color-border)', borderRadius: 4, fontSize: 'var(--ap-font-size)', width: 180 }}
            />
            <button
              onClick={() => { void previewAction('move') }}
              disabled={isDisabled || !targetFolder}
              style={moveBtnStyle}
            >
              {t('asset-pilot.bulk.review-move')}
            </button>
            <button onClick={() => { setShowMoveForm(false); op.clear() }} disabled={isDisabled} style={cancelBtnStyle}>
              {t('asset-pilot.common.cancel')}
            </button>
          </div>
        )}
      </div>

      <button onClick={() => { op.clear(); onDeselect() }} disabled={isDisabled} style={cancelBtnStyle}>{t('asset-pilot.bulk.deselect-all')}</button>

      {op.reviewedPlan != null && (
        <ConfirmDialog
          title={t(`asset-pilot.confirm.${op.reviewedPlan.action}-title`)}
          description={op.reviewedPlan.action === 'move'
            ? t('asset-pilot.confirm.move-description', { count: op.reviewedPlan.assetIds.length, folder: op.reviewedPlan.targetFolder })
            : t(`asset-pilot.confirm.${op.reviewedPlan.action}-description`, { count: op.reviewedPlan.assetIds.length })}
          details={<PlanEligibility result={op.reviewedPlan.result} />}
          confirmLabel={t(`asset-pilot.bulk.confirm-${op.reviewedPlan.action}`)}
          cancelLabel={t('asset-pilot.common.cancel')}
          variant={op.reviewedPlan.action === 'delete' ? 'danger' : 'warning'}
          loading={op.running}
          confirmDisabled={op.reviewedPlan.result.eligible === 0}
          onConfirm={() => { void applyReviewedAction() }}
          onCancel={() => op.clear()}
        />
      )}
    </div>
  )
}

const PlanEligibility: React.FC<{ result: PlannedBulkActionResult }> = ({ result }) => {
  const { t } = useTranslation()
  const errors = Object.entries(result.errors)

  return (
    <div style={{ marginTop: 10 }}>
      <p style={{ margin: '0 0 6px', fontWeight: 600 }}>
        {t('asset-pilot.bulk.eligibility-summary', { eligible: result.eligible, failed: result.failed })}
      </p>
      {errors.length > 0 && (
        <ul aria-label={t('asset-pilot.bulk.ineligible-assets')} style={{ margin: 0, paddingLeft: 20, maxHeight: 160, overflowY: 'auto' }}>
          {errors.map(([assetId, reason]) => <li key={assetId}>{t('asset-pilot.bulk.asset-error', { id: assetId, reason })}</li>)}
        </ul>
      )}
    </div>
  )
}

const deleteBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-error-border-hover)', borderRadius: 4, background: 'var(--ap-color-error-bg)',
  color: 'var(--ap-color-error-text)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
const moveBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-primary-border)', borderRadius: 4, background: 'var(--ap-color-primary-bg)',
  color: 'var(--ap-color-primary-active)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
const quarantineBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-warning-border-hover)', borderRadius: 4, background: 'var(--ap-color-warning-bg)',
  color: 'var(--ap-color-warning-text)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
const lockBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-warning-border)', borderRadius: 4, background: 'var(--ap-color-warning-bg)',
  color: 'var(--ap-color-warning-text-active)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
const unlockBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-info-border)', borderRadius: 4, background: 'var(--ap-color-info-bg)',
  color: 'var(--ap-color-info-text)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
const cancelBtnStyle: React.CSSProperties = {
  padding: '4px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 4, background: 'var(--ap-color-bg-container)',
  color: 'var(--ap-color-text-secondary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)',
}
