import React from 'react'
import { useTranslation } from 'react-i18next'
import { usePermissions } from '../../hooks/use-permissions'
import { assetPilotApi } from '../../services/api'
import type { DeadOperationDelivery, DeliveryRetryResponse } from '../../types'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { ReviewedMaintenancePanel } from './reviewed-maintenance-panel'
import { useReviewedMaintenancePlan } from './use-reviewed-maintenance-plan'
import {
  maintenanceCaptionStyle,
  maintenanceCellStyle,
  maintenanceHeaderStyle,
  maintenanceSummaryStyle,
  maintenanceTableWrapperStyle,
} from './maintenance-result-styles'

export const DeliveryRetryPanel: React.FC = () => {
  const { t } = useTranslation()
  const { admin } = usePermissions()
  const plan = useReviewedMaintenancePlan<DeliveryRetryResponse>({
    previewRequest: assetPilotApi.previewDeliveryRetry,
    applyRequest: assetPilotApi.applyDeliveryRetry,
    isValidResponse: isDeliveryRetryResponse,
    hasSameTargets: hasSameDeliveries,
    messages: {
      previewInvalid: t('asset-pilot.delivery-retry.preview-invalid'),
      applyInvalid: t('asset-pilot.delivery-retry.apply-invalid'),
      previewFailed: message => t('asset-pilot.delivery-retry.preview-failed', { message }),
      applyFailed: message => t('asset-pilot.delivery-retry.apply-failed', { message }),
      stale: t('asset-pilot.delivery-retry.plan-stale'),
      unknownError: t('asset-pilot.common.unknown-error'),
    },
  })

  if (!admin) return null

  const displayed = plan.applied ?? plan.reviewed?.response ?? null
  const canApply = plan.reviewed != null && plan.reviewed.response.deliveries.length > 0 && plan.reviewed.response.planToken != null

  return (
    <ReviewedMaintenancePanel
      title={t('asset-pilot.delivery-retry.title')}
      description={t('asset-pilot.delivery-retry.description')}
      limitLabel={t('asset-pilot.delivery-retry.limit')}
      limitHint={t('asset-pilot.delivery-retry.limit-hint')}
      limitInvalid={t('asset-pilot.delivery-retry.limit-invalid')}
      reviewLabel={t('asset-pilot.delivery-retry.review')}
      reviewingLabel={t('asset-pilot.delivery-retry.reviewing')}
      applyLabel={t('asset-pilot.delivery-retry.apply')}
      applyingLabel={t('asset-pilot.delivery-retry.applying')}
      limit={plan.limit}
      limitIsValid={plan.limitIsValid}
      action={plan.action}
      canApply={canApply}
      error={plan.error}
      onLimitChange={plan.changeLimit}
      onPreview={() => { void plan.preview() }}
      onApply={() => plan.setConfirming(true)}
    >
      {displayed != null && <DeliveryResults response={displayed} applied={plan.applied != null} />}
      {plan.confirming && plan.reviewed != null && (
        <ConfirmDialog
          title={t('asset-pilot.delivery-retry.confirm-title')}
          description={t('asset-pilot.delivery-retry.confirm-description', {
            count: plan.reviewed.response.count,
            limit: plan.reviewed.limit,
          })}
          confirmLabel={t('asset-pilot.delivery-retry.apply')}
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

const DeliveryResults: React.FC<{ response: DeliveryRetryResponse; applied: boolean }> = ({ response, applied }) => {
  const { t } = useTranslation()
  const summary = applied
    ? t('asset-pilot.delivery-retry.apply-complete', { count: response.count })
    : response.count === 0
      ? t('asset-pilot.delivery-retry.preview-empty')
      : t('asset-pilot.delivery-retry.preview-summary', { count: response.count })

  return (
    <div style={{ marginTop: 14 }}>
      <p role="status" style={maintenanceSummaryStyle}>{summary}</p>
      {response.deliveries.length > 0 && (
        <div style={maintenanceTableWrapperStyle}>
          <table style={tableStyle}>
            <caption style={maintenanceCaptionStyle}>{t(applied ? 'asset-pilot.delivery-retry.applied-results' : 'asset-pilot.delivery-retry.review-results')}</caption>
            <thead>
              <tr>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.delivery-retry.delivery-id')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.columns.operation')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.delivery-retry.delivery-key')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.delivery-retry.observer')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.delivery-retry.outcome')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.delivery-retry.attempts')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.delivery-retry.last-error')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.delivery-retry.updated-at')}</th>
                <th scope="col" style={maintenanceHeaderStyle}>{t('asset-pilot.delivery-retry.fingerprint')}</th>
              </tr>
            </thead>
            <tbody>
              {response.deliveries.map(delivery => <DeliveryRow key={delivery.deliveryId} delivery={delivery} />)}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

const DeliveryRow: React.FC<{ delivery: DeadOperationDelivery }> = ({ delivery }) => {
  const { t } = useTranslation()

  return (
    <tr style={{ borderTop: '1px solid var(--ap-color-border-secondary)' }}>
      <td style={technicalCellStyle}><code>{delivery.deliveryId}</code></td>
      <td style={maintenanceCellStyle}>{delivery.operationId}</td>
      <td style={technicalCellStyle}><code>{delivery.deliveryKey}</code></td>
      <td style={technicalCellStyle}><code>{delivery.observerId}</code></td>
      <td style={maintenanceCellStyle}>{t(`asset-pilot.delivery-retry.outcome.${delivery.outcome}`)}</td>
      <td style={maintenanceCellStyle}>{delivery.attempts}</td>
      <td style={maintenanceCellStyle}>{delivery.lastError ?? '-'}</td>
      <td style={technicalCellStyle}><time dateTime={delivery.updatedAt}>{delivery.updatedAt}</time></td>
      <td style={technicalCellStyle}><code>{delivery.fingerprint}</code></td>
    </tr>
  )
}

function isDeliveryRetryResponse(value: unknown, applied: boolean): value is DeliveryRetryResponse {
  if (value == null || typeof value !== 'object') return false
  const response = value as Record<string, unknown>
  if (response.applied !== applied || !Array.isArray(response.deliveries)) return false
  if (!Number.isInteger(response.count) || response.count !== response.deliveries.length) return false
  if (!response.deliveries.every(isDeadDelivery)) return false
  const deliveryIds = response.deliveries.map(delivery => (delivery as DeadOperationDelivery).deliveryId)
  if (new Set(deliveryIds).size !== deliveryIds.length) return false
  if (applied) return response.planToken === null
  return response.deliveries.length === 0
    ? response.planToken === null
    : typeof response.planToken === 'string' && response.planToken.trim() !== ''
}

function isDeadDelivery(value: unknown): value is DeadOperationDelivery {
  if (value == null || typeof value !== 'object') return false
  const delivery = value as Record<string, unknown>
  return isSha256(delivery.deliveryId)
    && Number.isInteger(delivery.operationId) && Number(delivery.operationId) > 0
    && typeof delivery.deliveryKey === 'string' && delivery.deliveryKey.trim() !== ''
    && typeof delivery.observerId === 'string' && delivery.observerId.trim() !== ''
    && (delivery.outcome === 'success' || delivery.outcome === 'failure')
    && Number.isInteger(delivery.attempts) && Number(delivery.attempts) >= 0
    && (delivery.lastError === null || typeof delivery.lastError === 'string')
    && typeof delivery.updatedAt === 'string' && !Number.isNaN(Date.parse(delivery.updatedAt))
    && isSha256(delivery.fingerprint)
}

function isSha256(value: unknown): value is string {
  return typeof value === 'string' && /^[a-f0-9]{64}$/.test(value)
}

function hasSameDeliveries(reviewed: DeliveryRetryResponse, applied: DeliveryRetryResponse): boolean {
  if (reviewed.deliveries.length !== applied.deliveries.length) return false
  const appliedById = new Map(applied.deliveries.map(delivery => [delivery.deliveryId, delivery]))
  return reviewed.deliveries.every(delivery => {
    const appliedDelivery = appliedById.get(delivery.deliveryId)
    return appliedDelivery != null
      && appliedDelivery.operationId === delivery.operationId
      && appliedDelivery.deliveryKey === delivery.deliveryKey
      && appliedDelivery.observerId === delivery.observerId
      && appliedDelivery.outcome === delivery.outcome
      && appliedDelivery.attempts === delivery.attempts
      && appliedDelivery.lastError === delivery.lastError
      && appliedDelivery.updatedAt === delivery.updatedAt
      && appliedDelivery.fingerprint === delivery.fingerprint
  })
}

const tableStyle: React.CSSProperties = { width: '100%', minWidth: 1400, borderCollapse: 'collapse', fontSize: 'var(--ap-font-size)' }
const technicalCellStyle: React.CSSProperties = { ...maintenanceCellStyle, maxWidth: 240, overflowWrap: 'anywhere' }
