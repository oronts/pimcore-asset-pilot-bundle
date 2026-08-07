import userEvent from '@testing-library/user-event'
import { screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { Pagination } from './pagination'

describe('Pagination', () => {
  it('announces the current page and disables unavailable navigation', async () => {
    const user = userEvent.setup()
    const onPage = vi.fn()
    renderWithI18n(<Pagination page={1} pages={12} onPage={onPage} />)

    expect(screen.getByRole('navigation', { name: 'Pagination' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Prev' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Page 1' })).toHaveAttribute('aria-current', 'page')

    await user.click(screen.getByRole('button', { name: 'Next' }))
    expect(onPage).toHaveBeenCalledWith(2)
  })

  it('supports configured and current custom page sizes', async () => {
    const user = userEvent.setup()
    const onLimit = vi.fn()
    renderWithI18n(
      <Pagination
        page={1}
        pages={1}
        onPage={vi.fn()}
        limit={30}
        onLimit={onLimit}
        pageSizeOptions={[20, 50]}
      />,
    )

    const select = screen.getByRole('combobox', { name: 'Rows per page' })
    expect(screen.getByRole('option', { name: '30 / page' })).toBeInTheDocument()
    await user.selectOptions(select, '50')
    expect(onLimit).toHaveBeenCalledWith(50)
  })

  it('renders nothing for a single page without a page-size selector', () => {
    const { container } = renderWithI18n(<Pagination page={1} pages={1} onPage={vi.fn()} />)

    expect(container).toBeEmptyDOMElement()
  })

  it('drives Prev/Next from hasMore when the total is withheld', async () => {
    const user = userEvent.setup()
    const onPage = vi.fn()
    renderWithI18n(<Pagination page={1} pages={null} hasMore onPage={onPage} />)

    expect(screen.getByRole('button', { name: 'Prev' })).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Page 1' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Next' }))
    expect(onPage).toHaveBeenCalledWith(2)
  })

  it('disables Next on the last cursor page and Prev off page one', () => {
    renderWithI18n(<Pagination page={3} pages={null} hasMore={false} onPage={vi.fn()} />)

    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Prev' })).not.toBeDisabled()
  })

  it('renders nothing in cursor mode on page one with no more results', () => {
    const { container } = renderWithI18n(<Pagination page={1} pages={null} hasMore={false} onPage={vi.fn()} />)

    expect(container).toBeEmptyDOMElement()
  })

  it('surfaces a truncation notice when the listing hit its scan ceiling', () => {
    renderWithI18n(<Pagination page={1} pages={null} hasMore={false} truncated onPage={vi.fn()} />)

    expect(screen.getByRole('status')).toHaveTextContent(/results are limited/i)
  })

  it('shows the truncation notice even when no pagination controls render', () => {
    renderWithI18n(<Pagination page={1} pages={1} truncated onPage={vi.fn()} />)

    expect(screen.getByRole('status')).toBeInTheDocument()
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument()
  })
})
