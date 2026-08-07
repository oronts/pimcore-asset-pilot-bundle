import React from 'react'
import { renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../components/shared/toast/toast-context'
import { useToast } from './use-toast'

describe('useToast', () => {
  it('fails loudly outside the dashboard provider', () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    const preventExpectedError = (event: ErrorEvent): void => event.preventDefault()
    window.addEventListener('error', preventExpectedError)
    try {
      expect(() => renderHook(() => useToast())).toThrow('useToast must be used inside ToastProvider')
    } finally {
      window.removeEventListener('error', preventExpectedError)
      consoleError.mockRestore()
    }
  })

  it('exposes every notification level inside the provider', () => {
    const wrapper: React.FC<{ children: React.ReactNode }> = ({ children }) => <ToastProvider>{children}</ToastProvider>
    const { result } = renderHook(() => useToast(), { wrapper })

    expect(Object.keys(result.current).sort()).toEqual(['error', 'info', 'success', 'warning'])
  })
})
