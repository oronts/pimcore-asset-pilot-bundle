import React from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { useModalDismiss } from './use-modal-dismiss'

interface ModalProps {
  active?: boolean
  enabled?: boolean
  onClose: () => void
}

const Modal: React.FC<ModalProps> = ({ active = true, enabled = true, onClose }) => {
  const ref = useModalDismiss<HTMLDivElement>(onClose, enabled, active)

  return (
    <div ref={ref} role="dialog" tabIndex={-1}>
      <button type="button">First</button>
      <input aria-label="Middle" />
      <button type="button">Last</button>
    </div>
  )
}

describe('useModalDismiss', () => {
  it('focuses the first control and restores the previous focus on unmount', () => {
    const onClose = vi.fn()
    const view = render(<button type="button">Trigger</button>)
    const trigger = screen.getByRole('button', { name: 'Trigger' })
    trigger.focus()

    view.rerender(
      <>
        <button type="button">Trigger</button>
        <Modal onClose={onClose} />
      </>,
    )

    expect(screen.getByRole('button', { name: 'First' })).toHaveFocus()

    view.rerender(<button type="button">Trigger</button>)
    expect(screen.getByRole('button', { name: 'Trigger' })).toHaveFocus()
  })

  it('closes on Escape only when dismissal is enabled and active', () => {
    const onClose = vi.fn()
    const view = render(<Modal onClose={onClose} enabled={false} />)

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(onClose).not.toHaveBeenCalled()

    view.rerender(<Modal onClose={onClose} active={false} />)
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(onClose).not.toHaveBeenCalled()

    view.rerender(<Modal onClose={onClose} />)
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('wraps focus in both tab directions', () => {
    render(<Modal onClose={vi.fn()} />)
    const first = screen.getByRole('button', { name: 'First' })
    const last = screen.getByRole('button', { name: 'Last' })

    last.focus()
    fireEvent.keyDown(document, { key: 'Tab' })
    expect(first).toHaveFocus()

    first.focus()
    fireEvent.keyDown(document, { key: 'Tab', shiftKey: true })
    expect(last).toHaveFocus()
  })
})
