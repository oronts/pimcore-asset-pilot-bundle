/**
 * Cross-instance handoff for the "Organize with Asset Pilot" tree context action (F9). The context-menu
 * provider records the clicked folder here and opens the dashboard; the dashboard consumes it once to switch
 * to the Operations tab and seed the Reorganize form. A module-level singleton (mirroring the toast id
 * counter) is this module's idiom for shared state, since no zustand/redux is present.
 */
type Listener = () => void

let pendingFolder: string | null = null
const listeners = new Set<Listener>()

export const organizeTargetStore = {
  /** Record the folder to organize and notify any mounted dashboard. */
  request(folder: string): void {
    pendingFolder = folder
    listeners.forEach((listener) => {
      listener()
    })
  },

  /** Look at the pending folder without clearing it (used by the dashboard to decide the active tab). */
  peek(): string | null {
    return pendingFolder
  },

  /** Read and clear the pending folder. Returns null when nothing is pending. The form is the sole consumer. */
  consume(): string | null {
    const folder = pendingFolder
    pendingFolder = null
    return folder
  },

  /** Subscribe to organize requests. Returns an unsubscribe function. */
  subscribe(listener: Listener): () => void {
    listeners.add(listener)
    return () => {
      listeners.delete(listener)
    }
  },
}
