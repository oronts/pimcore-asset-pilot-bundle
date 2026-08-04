import { type ContextMenuItemProvider } from '@pimcore/studio-ui-bundle/modules/app'
import { useTranslation } from 'react-i18next'
import { usePermissions } from '../hooks/use-permissions'
import { organizeTargetStore } from '../services/organize-target-store'
import { buildOrganizeMenuItem } from './organize-menu-item'

/** The subset of the SDK tree context the provider reads: the clicked node's type and folder path. */
interface TreeContextMenuContext {
  target?: { type?: string, fullPath?: string }
}

/**
 * Create the asset-tree context-menu provider that offers "Organize with Asset Pilot" on an asset folder.
 * Clicking it records the folder and opens the dashboard (the caller supplies how). The organize itself still
 * runs through the dashboard's reviewed preview/apply, so this never mutates in one click. Register it only on
 * the asset tree: the seeded Reorganize form is asset-folder scoped.
 */
export function createOrganizeContextMenuProvider(openDashboard: () => void): ContextMenuItemProvider {
  return {
    name: 'asset-pilot-organize',
    priority: 50,
    useMenuItem: (context: TreeContextMenuContext) => {
      const { t } = useTranslation()
      const { operate } = usePermissions()

      return buildOrganizeMenuItem({
        isFolder: context?.target?.type === 'folder',
        folder: context?.target?.fullPath,
        operate,
        label: t('asset-pilot.context-menu.organize'),
        onOrganize: (folder) => {
          organizeTargetStore.request(folder)
          openDashboard()
        },
      })
    },
  }
}
