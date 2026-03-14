import React from 'react'
import { injectStyles } from '../../utils/inject-styles'

interface ResponsiveTableWrapperProps {
  children: React.ReactNode
  stickyFirstColumn?: boolean
  tableId?: string
}

let wrapperCounter = 0

export const ResponsiveTableWrapper: React.FC<ResponsiveTableWrapperProps> = ({
  children, stickyFirstColumn = false, tableId,
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
      [data-ap-table="${id}"] tr { background: #fff; }
      [data-ap-table="${id}"] tr:hover { background: #fafafa; }
    `)
  }

  return (
    <div
      data-ap-table={id}
      style={{ overflowX: 'auto', position: 'relative' }}
    >
      {children}
    </div>
  )
}
