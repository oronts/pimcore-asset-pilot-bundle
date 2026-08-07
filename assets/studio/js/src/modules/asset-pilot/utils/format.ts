export function truncate(str: string | null, max: number): string {
  if (!str) return ''
  if (str.length <= max) return str
  return '...' + str.slice(-max)
}

export function formatBytes(bytes: number | null | undefined): string {
  if (bytes == null || !Number.isFinite(bytes) || bytes < 0) return '-'
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB']
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), sizes.length - 1)
  const value = bytes / Math.pow(k, i)
  return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(value)} ${sizes[i]}`
}

export function formatDate(dateStr: string | null, withYear = false, locale?: string): string {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  if (Number.isNaN(d.getTime())) return '-'
  const opts: Intl.DateTimeFormatOptions = withYear
    ? { day: '2-digit', month: 'short', year: 'numeric' }
    : { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }
  return d.toLocaleDateString(locale, opts)
}

// Readable fallback for a backend identifier (e.g. a consumer health-check name or an unmapped
// status) that has no explicit translation: "operation_run_backlog" -> "Operation run backlog".
export function humanizeIdentifier(key: string): string {
  const spaced = key.replace(/[._-]+/g, ' ').trim()
  if (spaced === '') return key
  return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

export function pageNumbers(current: number, total: number): number[] {
  const pages: number[] = []
  const start = Math.max(1, Math.min(current - 4, total - 9))
  const end = Math.min(total, start + 9)
  for (let i = start; i <= end; i++) pages.push(i)
  return pages
}
