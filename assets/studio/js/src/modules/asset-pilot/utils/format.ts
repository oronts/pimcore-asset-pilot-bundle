export function truncate(str: string | null, max: number): string {
  if (!str) return ''
  if (str.length <= max) return str
  return '...' + str.slice(-max)
}

export function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

export function formatDate(dateStr: string | null, withYear = false): string {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  const opts: Intl.DateTimeFormatOptions = withYear
    ? { day: '2-digit', month: 'short', year: 'numeric' }
    : { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }
  return d.toLocaleDateString('en-GB', opts)
}

export function pageNumbers(current: number, total: number): number[] {
  const pages: number[] = []
  const start = Math.max(1, current - 4)
  const end = Math.min(total, start + 9)
  for (let i = start; i <= end; i++) pages.push(i)
  return pages
}
