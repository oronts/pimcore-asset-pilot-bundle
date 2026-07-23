import userEvent from '@testing-library/user-event'
import { screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { SortableHeader } from './sortable-header'

describe('SortableHeader', () => {
  it('exposes the active sort direction and toggles its field', async () => {
    const user = userEvent.setup()
    const onToggle = vi.fn()
    renderWithI18n(
      <table>
        <thead>
          <tr>
            <SortableHeader
              label="Filename"
              field="filename"
              currentField="filename"
              direction="desc"
              onToggle={onToggle}
            />
          </tr>
        </thead>
      </table>,
    )

    expect(screen.getByRole('columnheader')).toHaveAttribute('aria-sort', 'descending')
    await user.click(screen.getByRole('button', { name: 'Filename' }))
    expect(onToggle).toHaveBeenCalledWith('filename')
  })

  it('marks an inactive column as unsorted', () => {
    renderWithI18n(
      <table>
        <thead>
          <tr>
            <SortableHeader
              label="Size"
              field="size"
              currentField="filename"
              direction="asc"
              onToggle={vi.fn()}
            />
          </tr>
        </thead>
      </table>,
    )

    expect(screen.getByRole('columnheader')).toHaveAttribute('aria-sort', 'none')
  })
})
