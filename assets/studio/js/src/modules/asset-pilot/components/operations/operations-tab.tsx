import React from 'react'
import { OrganizeForm } from './organize-form'
import { BulkOrganizeForm } from './bulk-organize-form'
import { ReplayFailuresForm } from './replay-failures-form'
import { OperationStatus } from './operation-status'

export const OperationsTab: React.FC = () => (
  <div style={{ display: 'flex', flexDirection: 'column', gap: 28 }}>
    <OrganizeForm />
    <div style={{ borderTop: '1px solid #f0f0f0' }} />
    <BulkOrganizeForm />
    <div style={{ borderTop: '1px solid #f0f0f0' }} />
    <ReplayFailuresForm />
    <div style={{ borderTop: '1px solid #f0f0f0' }} />
    <OperationStatus />
  </div>
)
