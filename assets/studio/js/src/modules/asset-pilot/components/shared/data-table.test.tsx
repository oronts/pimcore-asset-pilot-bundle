import userEvent from '@testing-library/user-event'
import { screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { DataTable, type DataColumn } from './data-table'

interface Row {
  id: number
  name: string
  size: number
}

const columns: DataColumn<Row>[] = [
  { key: 'name', label: 'Name', priority: 1, sortField: 'name', cell: row => row.name },
  { key: 'size', label: 'Size', priority: 2, cell: row => row.size },
]

const data = {
  items: [{ id: 1, name: 'One', size: 12 }],
  total: 1,
  page: 1,
  pages: 2,
}

describe('DataTable', () => {
  it('announces request errors through a live alert', () => {
    renderWithI18n(
      <DataTable<Row>
        columns={columns}
        data={null}
        loading={false}
        error="Could not load assets"
        onPage={vi.fn()}
        tableId="error-table"
        empty={<p>Empty</p>}
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('Could not load assets')
  })

  it('offers a retry affordance that invokes the supplied callback', async () => {
    const user = userEvent.setup()
    const onRetry = vi.fn()
    renderWithI18n(
      <DataTable<Row>
        columns={columns}
        data={null}
        loading={false}
        error="Could not load assets"
        onRetry={onRetry}
        onPage={vi.fn()}
        tableId="retry-table"
        empty={<p>Empty</p>}
      />,
    )

    await user.click(screen.getByRole('button', { name: 'Retry' }))
    expect(onRetry).toHaveBeenCalledOnce()
  })

  it('renders loading and empty states without stale rows', () => {
    const view = renderWithI18n(
      <DataTable<Row>
        columns={columns}
        data={null}
        loading
        onPage={vi.fn()}
        tableId="state-table"
        empty={<p>No rows</p>}
      />,
    )
    expect(screen.getByRole('table')).toBeInTheDocument()

    view.rerender(
      <DataTable<Row>
        columns={columns}
        data={{ items: [], total: 0, page: 1, pages: 1 }}
        loading={false}
        onPage={vi.fn()}
        tableId="state-table"
        empty={<p>No rows</p>}
      />,
    )
    expect(screen.getByText('No rows')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })

  it('wires sorting, selection, and pagination through accessible controls', async () => {
    const user = userEvent.setup()
    const toggleAll = vi.fn()
    const toggleSelect = vi.fn()
    const onSort = vi.fn()
    const onPage = vi.fn()
    renderWithI18n(
      <DataTable<Row>
        columns={columns}
        data={data}
        loading={false}
        onPage={onPage}
        tableId="asset-table"
        empty={<p>Empty</p>}
        selection={{
          selected: new Set(),
          allSelected: false,
          toggleAll,
          toggleSelect,
        }}
        sort={{ field: 'name', direction: 'asc', onToggle: onSort }}
      />,
    )

    expect(screen.getByRole('columnheader', { name: 'Name' })).toHaveAttribute('aria-sort', 'ascending')
    expect(screen.getByText('One')).toBeInTheDocument()

    await user.click(screen.getByRole('checkbox', { name: 'Select all' }))
    await user.click(screen.getByRole('checkbox', { name: 'Select row 1' }))
    await user.click(screen.getByRole('button', { name: 'Name' }))
    await user.click(screen.getByRole('button', { name: 'Next' }))

    expect(toggleAll).toHaveBeenCalledOnce()
    expect(toggleSelect).toHaveBeenCalledWith(1)
    expect(onSort).toHaveBeenCalledWith('name')
    expect(onPage).toHaveBeenCalledWith(2)
  })

  it('renders ineligible rows and an all-ineligible page as disabled selection controls', async () => {
    const toggleSelect = vi.fn()
    const toggleAll = vi.fn()
    renderWithI18n(
      <DataTable<Row>
        columns={columns}
        data={data}
        loading={false}
        onPage={vi.fn()}
        tableId="locked-asset-table"
        empty={<p>Empty</p>}
        selection={{
          selected: new Set(),
          allSelected: false,
          toggleAll,
          toggleSelect,
          isSelectable: () => false,
        }}
      />,
    )

    expect(screen.getByRole('checkbox', { name: 'Select all' })).toBeDisabled()
    expect(screen.getByRole('checkbox', { name: 'Row 1 cannot be selected' })).toBeDisabled()
    expect(toggleAll).not.toHaveBeenCalled()
    expect(toggleSelect).not.toHaveBeenCalled()
  })
})
