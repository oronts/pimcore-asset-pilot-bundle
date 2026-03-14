const injected = new Set<string>()

export function injectStyles(id: string, css: string): void {
  if (injected.has(id)) return
  if (typeof document === 'undefined') return
  if (document.getElementById(id) != null) {
    injected.add(id)
    return
  }
  const style = document.createElement('style')
  style.id = id
  style.textContent = css
  document.head.appendChild(style)
  injected.add(id)
}
