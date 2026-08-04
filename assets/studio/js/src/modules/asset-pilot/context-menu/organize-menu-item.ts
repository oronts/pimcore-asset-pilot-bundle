import { type ContextMenuItemProvider } from '@pimcore/studio-ui-bundle/modules/app'

/** The item type a tree context-menu provider returns (`ItemType | null`), derived from the SDK contract. */
export type OrganizeMenuItem = ReturnType<ContextMenuItemProvider['useMenuItem']>

export interface OrganizeMenuItemParams {
  /** Whether the clicked node is a folder (Reorganize is folder-scoped, so leaf nodes must not offer it). */
  isFolder: boolean
  /** The clicked node's folder path, or undefined when the node has no path. */
  folder: string | undefined
  /** Whether the current actor may operate (organize). */
  operate: boolean
  /** Localized label for the item. */
  label: string
  /** Invoked with the folder when the item is clicked. */
  onOrganize: (folder: string) => void
}

/**
 * Build the "Organize with Asset Pilot" context-menu item, or null when it must not appear (the actor cannot
 * operate, or the clicked node is not a folder with a path). Reorganize acts on a folder, so leaf assets never
 * offer it. Pure, so the gating and click wiring are unit-testable without the Studio SDK or React.
 */
export function buildOrganizeMenuItem({ isFolder, folder, operate, label, onOrganize }: OrganizeMenuItemParams): OrganizeMenuItem {
  if (!operate || !isFolder) {
    return null
  }
  if (folder === undefined || folder === '') {
    return null
  }

  return {
    key: 'asset-pilot-organize',
    label,
    onClick: () => {
      onOrganize(folder)
    },
  }
}
