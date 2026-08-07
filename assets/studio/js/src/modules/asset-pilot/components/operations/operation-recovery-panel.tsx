import React from 'react'
import { useTranslation } from 'react-i18next'
import { usePermissions } from '../../hooks/use-permissions'
import { assetPilotApi } from '../../services/api'
import type { OperationRecoveryResponse, OperationRecoveryResult } from '../../types'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { OpenButton } from '../shared/open-button'
import { StatusTag } from '../shared/status-tag'
import { visuallyHiddenStyle } from '../shared/visually-hidden-style'
import { useReviewedMaintenancePlan } from './use-reviewed-maintenance-plan'
import { ReviewedMaintenancePanel } from './reviewed-maintenance-panel'
import {
  maintenanceCaptionStyle,
  maintenanceCellStyle,
  maintenanceHeaderStyle,
  maintenanceSummaryStyle,
  maintenanceTableWrapperStyle,
} from './maintenance-result-styles'

export const OperationRecoveryPanel: React.FC = () => {
  const { t } = useTranslation()
  const { admin } = usePermissions()
  const plan = useReviewedMaintenancePlan<OperationRecoveryResponse>({
    previewRequest: assetPilotApi.previewOperationRecovery,
    applyRequest: assetPilotApi.applyOperationRecovery,
    isValidResponse: isRecoveryResponse,
    hasSameTargets: (reviewed, applied) => hasSameRecoveryTargets(reviewed.results, applied.results),
    messages: {
      previewInvalid: t('asset-pilot.recovery.preview-invalid'),
      applyInvalid: t('asset-pilot.recovery.apply-invalid'),
      previewFailed: message => t('asset-pilot.recovery.preview-failed', { message }),
      applyFailed: message => t('asset-pilot.recovery.apply-failed', { message }),
      stale: t('asset-pilot.recovery.plan-stale'),
      unknownError: t('asset-pilot.common.unknown-error'),
    },
  })

  if (!admin) return null
  const displayed = plan.applied ?? plan.reviewed?.response ?? null
  const isAppliedResult = plan.applied != null
  const canApply = plan.reviewed != null && plan.reviewed.response.results.length > 0 && plan.reviewed.response.planToken != null

  return (
    <ReviewedMaintenancePanel
      title={t('asset-pilot.recovery.title')}
      description={t('asset-pilot.recovery.description')}
      limitLabel={t('asset-pilot.recovery.limit')}
      limitHint={t('asset-pilot.recovery.limit-hint')}
      limitInvalid={t('asset-pilot.recovery.limit-invalid')}
      reviewLabel={t('asset-pilot.recovery.review')}
      reviewingLabel={t('asset-pilot.recovery.reviewing')}
      applyLabel={t('asset-pilot.recovery.apply')}
      applyingLabel={t('asset-pilot.recovery.applying')}
      limit={plan.limit}
      limitIsValid={plan.limitIsValid}
      action={plan.action}
      canApply={canApply}
      error={plan.error}
      onLimitChange={plan.changeLimit}
      onPreview={() => { void plan.preview() }}
      onApply={() => plan.setConfirming(true)}
    >
      {displayed != null && <RecoveryResults response={displayed} applied={isAppliedResult} />}

      {plan.confirming && plan.reviewed != null && (
        <ConfirmDialog
          title={t('asset-pilot.recovery.confirm-title')}
          description={t('asset-pilot.recovery.confirm-description', {
            count: plan.reviewed.response.results.length,
            limit: plan.reviewed.limit,
          })}
          confirmLabel={t('asset-pilot.recovery.apply')}
          variant="warning"
          loading={plan.action === 'apply'}
          confirmDisabled={plan.reviewed.response.planToken == null}
          onConfirm={() => { void plan.apply() }}
          onCancel={() => plan.setConfirming(false)}
        />
      )}
    </ReviewedMaintenancePanel>
  )
}

const RecoveryResults: React.FC<{ response: OperationRecoveryResponse; applied: boolean }> = ({ response, applied }) => {
  const { t } = useTranslation()
  const summary = applied
    ? response.unresolved > 0
      ? t('asset-pilot.recovery.apply-unresolved', { unresolved: response.unresolved })
      : t('asset-pilot.recovery.apply-complete', { count: response.results.length })
    : response.results.length === 0
      ? t('asset-pilot.recovery.preview-empty')
      : t('asset-pilot.recovery.preview-summary', { count: response.results.length, unresolved: response.unresolved })

  return (
    <div style={{ marginTop: 14 }}>
      <p role={applied && response.unresolved > 0 ? 'alert' : 'status'} style={applied && response.unresolved > 0 ? warningStyle : maintenanceSummaryStyle}>
        {summary}
      </p>
      {response.results.length > 0 && (
        <div style={maintenanceTableWrapperStyle}>
          <table style={tableStyle}>
            <caption style={maintenanceCaptionStyle}>{t(applied ? 'asset-pilot.recovery.applied-results' : 'asset-pilot.recovery.review-results')}</caption>
            <thead>
              <tr>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.columns.operation')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.columns.asset')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.recovery.kind')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.recovery.classification')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.recovery.journal')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.columns.message')}</th>
              </tr>
            </thead>
            <tbody>
              {response.results.map(result => (
                <RecoveryResultRow key={result.operationId} result={result} applied={applied} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

const RecoveryResultRow: React.FC<{ result: OperationRecoveryResult; applied: boolean }> = ({ result, applied }) => {
  const { t } = useTranslation()
  const unresolved = applied && (!result.journalUpdated || result.classification === 'recovery_required')
  const journalKey = result.journalUpdated ? 'updated' : applied ? 'not-updated' : 'pending'

  return (
    <tr style={{ borderTop: '1px solid var(--ap-color-border-secondary)', background: unresolved ? 'var(--ap-color-warning-bg)' : undefined }}>
      <td style={maintenanceCellStyle}>{result.operationId}</td>
      <td style={maintenanceCellStyle}><OpenButton id={result.assetId} type="asset" /></td>
      <td style={maintenanceCellStyle}>{t(`asset-pilot.recovery.kind.${result.kind}`)}</td>
      <td style={maintenanceCellStyle}><StatusTag status={result.classification} /></td>
      <td style={maintenanceCellStyle}>
        {unresolved && <span style={visuallyHiddenStyle}>{t('asset-pilot.recovery.unresolved-row')}: </span>}
        {t(`asset-pilot.recovery.journal.${journalKey}`)}
      </td>
      <td style={maintenanceCellStyle}>{result.message}</td>
    </tr>
  )
}

function isRecoveryResponse(value: unknown, applied: boolean): value is OperationRecoveryResponse {
  if (value == null || typeof value !== 'object') return false
  const response = value as Record<string, unknown>
  if (response.applied !== applied || !Array.isArray(response.results)) return false
  if (!Number.isInteger(response.unresolved) || Number(response.unresolved) < 0 || Number(response.unresolved) > response.results.length) return false
  if (!response.results.every(isRecoveryResult)) return false
  const operationIds = response.results.map(result => (result as OperationRecoveryResult).operationId)
  if (new Set(operationIds).size !== operationIds.length) return false
  if (applied) return response.planToken === null
  return response.results.length === 0
    ? response.planToken === null
    : typeof response.planToken === 'string' && response.planToken.trim() !== ''
}

function isRecoveryResult(value: unknown): value is OperationRecoveryResult {
  if (value == null || typeof value !== 'object') return false
  const result = value as Record<string, unknown>
  return Number.isInteger(result.operationId) && Number(result.operationId) > 0
    && Number.isInteger(result.assetId) && Number(result.assetId) > 0
    && (result.kind === 'move' || result.kind === 'revert')
    && (result.classification === 'completed' || result.classification === 'failed' || result.classification === 'recovery_required')
    && typeof result.journalUpdated === 'boolean'
    && typeof result.message === 'string' && result.message.trim() !== ''
}

function hasSameRecoveryTargets(reviewed: OperationRecoveryResult[], applied: OperationRecoveryResult[]): boolean {
  if (reviewed.length !== applied.length) return false
  const appliedByOperation = new Map(applied.map(result => [result.operationId, result]))
  return reviewed.every(result => {
    const appliedResult = appliedByOperation.get(result.operationId)
    return appliedResult?.assetId === result.assetId && appliedResult.kind === result.kind
  })
}

const warningStyle: React.CSSProperties = { margin: 0, padding: '8px 10px', border: '1px solid var(--ap-color-warning-border)', borderRadius: 5, background: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text-active)', fontSize: 'var(--ap-font-size)' }
const tableStyle: React.CSSProperties = { width: '100%', minWidth: 760, borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)' }
