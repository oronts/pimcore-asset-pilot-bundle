export type ColumnPriority = 1 | 2 | 3

export interface ColumnConfig {
  key: string
  priority: ColumnPriority
}

export function getVisibleColumns(columns: ColumnConfig[], containerWidth: number): Set<string> {
  const visible = new Set<string>()
  for (const col of columns) {
    if (col.priority === 1) {
      visible.add(col.key)
    } else if (col.priority === 2 && containerWidth >= 768) {
      visible.add(col.key)
    } else if (col.priority === 3 && containerWidth >= 1024) {
      visible.add(col.key)
    }
  }
  return visible
}
