import { container, type AbstractModule } from '@pimcore/studio-ui-bundle'
import { serviceIds } from '@pimcore/studio-ui-bundle/app'
import { type WidgetRegistry, type WidgetManagerActionService } from '@pimcore/studio-ui-bundle/modules/widget-manager'
import { type MainNavRegistry, type ContextMenuRegistry, contextMenuConfig } from '@pimcore/studio-ui-bundle/modules/app'
import { AssetPilotDashboard } from './components/asset-pilot-dashboard'
import { createOrganizeContextMenuProvider } from './context-menu/organize-context-menu'

export const AssetPilotModule: AbstractModule = {
  onInit: (): void => {
    const widgetRegistryService = container.get<WidgetRegistry>(serviceIds.widgetManager)
    widgetRegistryService.registerWidget({
      name: 'asset-pilot-dashboard',
      component: AssetPilotDashboard
    })

    const mainNavRegistryService = container.get<MainNavRegistry>(serviceIds.mainNavRegistry)
    mainNavRegistryService.registerMainNavItem({
      path: 'ExperienceEcommerce/Asset Pilot',
      label: 'Asset Pilot',
      order: 500,
      className: 'item-style-modifier',
      permission: 'asset_pilot_view',
      widgetConfig: {
        name: 'Asset Pilot',
        id: 'asset-pilot-dashboard',
        component: 'asset-pilot-dashboard',
        config: {
          translationKey: 'Asset Pilot',
          icon: {
            type: 'name',
            value: 'folder'
          }
        }
      }
    })

    // F9: an asset-folder tree context action that opens the dashboard on the clicked folder, so an organize
    // can be started from the tree. The reviewed preview/apply still runs inside the dashboard. Asset tree only:
    // the seeded Reorganize form is asset-folder scoped, so an object-tree path would not resolve.
    const contextMenuRegistry = container.get<ContextMenuRegistry>(serviceIds['App/ContextMenuRegistry/ContextMenuRegistry'])
    const widgetActionService = container.get<WidgetManagerActionService>(serviceIds.widgetManagerActionService)
    const organizeProvider = createOrganizeContextMenuProvider(() => {
      widgetActionService.openMainWidget({
        name: 'Asset Pilot',
        id: 'asset-pilot-dashboard',
        component: 'asset-pilot-dashboard',
        config: {
          translationKey: 'Asset Pilot',
          icon: { type: 'name', value: 'folder' }
        }
      })
    })
    contextMenuRegistry.registerToSlot(contextMenuConfig.assetTree.name, organizeProvider)
  }
}
