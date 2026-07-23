import React from 'react'
import { injectStyles } from '../../utils/inject-styles'

interface ResponsiveTableWrapperProps {
  children: React.ReactNode
  stickyFirstColumn?: boolean
  tableId?: string
  /** Accessible name for the horizontally scrollable region so keyboard/screen-reader users can reach it. */
  label: string
}

let wrapperCounter = 0

export const ResponsiveTableWrapper: React.FC<ResponsiveTableWrapperProps> = ({
  children, stickyFirstColumn = false, tableId, label,
}) => {
  const id = React.useMemo(() => tableId ?? `ap-tw-${++wrapperCounter}`, [tableId])

  if (stickyFirstColumn) {
    injectStyles(`ap-sticky-${id}`, `
      [data-ap-table="${id}"] th:first-child,
      [data-ap-table="${id}"] td:first-child {
        position: sticky;
        left: 0;
        z-index: 1;
        background: inherit;
      }
      [data-ap-table="${id}"] tr { background: var(--ap-color-bg-container); }
      [data-ap-table="${id}"] tr:hover { background: var(--ap-color-fill-alter); }
    `)
  }

  return (
    <div
      data-ap-table={id}
      role={label != null ? 'region' : undefined}
      aria-label={label}
      tabIndex={0}
      style={{ overflowX: 'auto', position: 'relative' }}
    >
      {children}
    </div>
  )
}
