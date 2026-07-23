import React from 'react'
import { fireEvent, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { renderWithI18n } from '../../../../../test/render'
import { ConfirmDialog } from './confirm-dialog'

function renderDialog(overrides: Partial<React.ComponentProps<typeof ConfirmDialog>> = {}) {
  const props: React.ComponentProps<typeof ConfirmDialog> = {
    title: 'Delete assets',
    description: 'This cannot be undone.',
    confirmLabel: 'Delete',
    variant: 'danger',
    onConfirm: vi.fn(),
    onCancel: vi.fn(),
    ...overrides,
  }

  return { props, ...renderWithI18n(<ConfirmDialog {...props} />) }
}

describe('ConfirmDialog', () => {
  it('labels the alert dialog and gives initial focus to cancel', () => {
    renderDialog()

    expect(screen.getByRole('alertdialog', { name: 'Delete assets' })).toHaveAccessibleDescription('This cannot be undone.')
    expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus()
  })

  it('confirms, cancels, and supports Escape dismissal', async () => {
    const user = userEvent.setup()
    const { props } = renderDialog()

    await user.click(screen.getByRole('button', { name: 'Delete' }))
    expect(props.onConfirm).toHaveBeenCalledOnce()

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(props.onCancel).toHaveBeenCalledOnce()
  })

  it('blocks every dismissal route while loading', () => {
    const { props, container } = renderDialog({ loading: true })

    fireEvent.keyDown(document, { key: 'Escape' })
    fireEvent.click(container.firstElementChild as Element)

    expect(props.onCancel).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Processing...' })).toBeDisabled()
  })

  it('renders review details and can disable confirmation', () => {
    renderDialog({
      details: <ul><li>Asset 42 is locked</li></ul>,
      confirmDisabled: true,
    })

    expect(screen.getByRole('alertdialog')).toHaveAccessibleDescription('This cannot be undone. Asset 42 is locked')
    expect(screen.getByRole('button', { name: 'Delete' })).toBeDisabled()
  })
})
