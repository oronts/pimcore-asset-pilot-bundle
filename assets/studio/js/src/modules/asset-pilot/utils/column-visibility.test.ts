import { describe, expect, it } from 'vitest'
import { getVisibleColumns, type ColumnConfig } from './column-visibility'

const columns: ColumnConfig[] = [
  { key: 'primary', priority: 1 },
  { key: 'medium', priority: 2 },
  { key: 'wide', priority: 3 },
]

describe('getVisibleColumns', () => {
  it.each([
    [767, ['primary']],
    [768, ['primary', 'medium']],
    [1023, ['primary', 'medium']],
    [1024, ['primary', 'medium', 'wide']],
  ])('returns the columns visible at %d pixels', (width, expected) => {
    expect([...getVisibleColumns(columns, width)]).toEqual(expected)
  })
})
