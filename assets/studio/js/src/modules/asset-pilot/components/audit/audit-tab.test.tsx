import { screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import type { AuditEntry } from '../../types'
import { AuditTab } from './audit-tab'

const entries: AuditEntry[] = [
  {
    id: 1,
    asset_id: 7,
    asset_path_from: '/incoming/a.jpg',
    asset_path_to: '/catalog/a.jpg',
    object_id: 42,
    object_class: 'Product',
    rule_name: 'catalog',
    trigger_type: 'manual',
    status: 'completed_with_observer_error',
    error_message: 'Webhook delivery failed after the move committed',
    duration_ms: 12,
    user_id: 9,
    created_at: '2026-07-15T08:00:00+00:00',
    operation_kind: 'move',
    actor_type: 'user',
    parent_audit_id: null,
    schema_version: 1,
    updated_at: '2026-07-15T08:00:01+00:00',
    committed_at: '2026-07-15T08:00:01+00:00',
  },
  {
    id: 2,
    asset_id: 7,
    asset_path_from: '/catalog/a.jpg',
    asset_path_to: '/incoming/a.jpg',
    object_id: 42,
    object_class: 'Product',
    rule_name: 'catalog',
    trigger_type: 'manual',
    status: 'completed',
    error_message: null,
    duration_ms: 8,
    user_id: null,
    created_at: '2026-07-15T08:05:00+00:00',
    operation_kind: 'revert',
    actor_type: 'system',
    parent_audit_id: 1,
    schema_version: 1,
    updated_at: '2026-07-15T08:05:01+00:00',
    committed_at: '2026-07-15T08:05:01+00:00',
  },
]

vi.mock('../../hooks/use-asset-pilot-api', () => ({
  useAudit: () => ({
    data: { items: entries, total: entries.length, page: 1, pages: 1 },
    loading: false,
    error: null,
    refetch: vi.fn(),
  }),
}))

vi.mock('../../hooks/use-permissions', () => ({ usePermissions: () => ({ admin: true }) }))
vi.mock('../shared/open-button', () => ({ OpenButton: ({ id }: { id: number }) => <span>#{id}</span> }))

describe('AuditTab', () => {
  it('shows durable journal diagnostics and only offers revert for committed moves', () => {
    renderWithI18n(<AuditTab />)

    expect(screen.getByText('Move')).toBeInTheDocument()
    expect(screen.getByText('Revert', { selector: 'td' })).toHaveAttribute('title', 'Reverts operation #1')
    expect(screen.getByText('User #9')).toBeInTheDocument()
    expect(screen.getByText('System')).toBeInTheDocument()
    expect(screen.getByTitle('Webhook delivery failed after the move committed')).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Revert' })).toHaveLength(1)
    expect(screen.getByRole('button', { name: 'Export CSV' })).toHaveStyle({ color: 'rgba(0, 0, 0, 0.88)' })
  })
})
