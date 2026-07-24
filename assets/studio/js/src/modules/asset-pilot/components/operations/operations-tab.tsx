import React from 'react'
import { OrganizeForm } from './organize-form'
import { BulkOrganizeForm } from './bulk-organize-form'
import { ReplayFailuresForm } from './replay-failures-form'
import { OperationStatus } from './operation-status'
import { ReorganizeForm } from './reorganize-form'
import { RecentOperationRuns } from './recent-operation-runs'
import { OperationRecoveryPanel } from './operation-recovery-panel'
import { DeliveryRetryPanel } from './delivery-retry-panel'

export const OperationsTab: React.FC = () => (
  <div style={{ display: 'flex', flexDirection: 'column', gap: 28 }}>
    <OrganizeForm />
    <div style={{ borderTop: '1px solid var(--ap-color-border-secondary)' }} />
    <BulkOrganizeForm />
    <div style={{ borderTop: '1px solid var(--ap-color-border-secondary)' }} />
    <ReplayFailuresForm />
    <div style={{ borderTop: '1px solid var(--ap-color-border-secondary)' }} />
    <ReorganizeForm />
    <div style={{ borderTop: '1px solid var(--ap-color-border-secondary)' }} />
    <RecentOperationRuns />
    <div style={{ borderTop: '1px solid var(--ap-color-border-secondary)' }} />
    <OperationStatus />
    <OperationRecoveryPanel />
    <DeliveryRetryPanel />
  </div>
)
