import { describe, expect, it } from 'vitest'
import { formatBytes, formatDate, humanizeIdentifier, pageNumbers, truncate } from './format'

describe('format utilities', () => {
  it('truncates from the beginning while retaining the path tail', () => {
    expect(truncate('/a/very/long/file.jpg', 12)).toBe('...ong/file.jpg')
    expect(truncate('short', 12)).toBe('short')
    expect(truncate(null, 12)).toBe('')
  })

  it('formats valid byte counts and rejects unknown values', () => {
    expect(formatBytes(0)).toBe('0 B')
    expect(formatBytes(1536)).toBe('1.5 KiB')
    expect(formatBytes(-1)).toBe('-')
    expect(formatBytes(Number.NaN)).toBe('-')
  })

  it('formats valid dates and safely rejects invalid input', () => {
    expect(formatDate('2024-01-15T12:00:00Z', true, 'en-US')).toContain('2024')
    expect(formatDate('not-a-date')).toBe('-')
    expect(formatDate(null)).toBe('-')
  })

  it('keeps a full ten-page window at both boundaries', () => {
    expect(pageNumbers(1, 20)).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
    expect(pageNumbers(20, 20)).toEqual([11, 12, 13, 14, 15, 16, 17, 18, 19, 20])
    expect(pageNumbers(3, 3)).toEqual([1, 2, 3])
  })

  it('humanizes backend identifiers as a readable fallback', () => {
    expect(humanizeIdentifier('operation_run_backlog')).toBe('Operation run backlog')
    expect(humanizeIdentifier('completed_with_observer_error')).toBe('Completed with observer error')
    expect(humanizeIdentifier('my.custom-check')).toBe('My custom check')
    expect(humanizeIdentifier('')).toBe('')
  })
})
