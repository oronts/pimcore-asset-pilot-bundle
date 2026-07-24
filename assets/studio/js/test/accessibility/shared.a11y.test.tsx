import { describe, it, vi } from 'vitest'
import { renderWithI18n } from '../render'
import { expectNoAccessibilityViolations } from '../accessibility'
import { ConfirmDialog } from '../../src/modules/asset-pilot/components/shared/confirm-dialog'
import { DataTable, type DataColumn } from '../../src/modules/asset-pilot/components/shared/data-table'
import { Pagination } from '../../src/modules/asset-pilot/components/shared/pagination'
import { SortableHeader } from '../../src/modules/asset-pilot/components/shared/sortable-header'
import { TrendChart } from '../../src/modules/asset-pilot/components/shared/charts/trend-chart'

interface Row {
  id: number
  name: string
}

const columns: DataColumn<Row>[] = [
  { key: 'name', label: 'Name', priority: 1, sortField: 'name', cell: row => row.name },
]

describe('shared component accessibility', () => {
  it('has no detectable violations in the confirmation dialog', async () => {
    const { container } = renderWithI18n(
      <ConfirmDialog
        title="Delete assets"
        description="This cannot be undone."
        confirmLabel="Delete"
        variant="danger"
        onConfirm={vi.fn()}
        onCancel={vi.fn()}
      />,
    )

    await expectNoAccessibilityViolations(container)
  })

  it('has no detectable violations in the data table', async () => {
    const { container } = renderWithI18n(
      <DataTable<Row>
        columns={columns}
        data={{ items: [{ id: 1, name: 'One' }], total: 1, page: 1, pages: 2 }}
        loading={false}
        onPage={vi.fn()}
        tableId="accessible-table"
        empty={<p>No rows</p>}
        selection={{
          selected: new Set(),
          allSelected: false,
          toggleAll: vi.fn(),
          toggleSelect: vi.fn(),
        }}
        sort={{ field: 'name', direction: 'asc', onToggle: vi.fn() }}
      />,
    )

    await expectNoAccessibilityViolations(container)
  })

  it('has no detectable violations in pagination and a sortable header', async () => {
    const { container } = renderWithI18n(
      <>
        <table>
          <thead>
            <tr>
              <SortableHeader
                label="Name"
                field="name"
                currentField="name"
                direction="asc"
                onToggle={vi.fn()}
              />
            </tr>
          </thead>
        </table>
        <Pagination page={2} pages={4} onPage={vi.fn()} />
      </>,
    )

    await expectNoAccessibilityViolations(container)
  })

  it('has no detectable violations in the localized trend chart', async () => {
    const { container } = renderWithI18n(
      <TrendChart points={[{ label: 'June', value: 1 }, { label: 'July', value: 2 }]} />,
    )

    await expectNoAccessibilityViolations(container)
  })
})
