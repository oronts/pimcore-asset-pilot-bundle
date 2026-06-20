import { container, type AbstractModule } from '@pimcore/studio-ui-bundle'
import { serviceIds } from '@pimcore/studio-ui-bundle/app'
import { type WidgetRegistry } from '@pimcore/studio-ui-bundle/modules/widget-manager'
import { type MainNavRegistry } from '@pimcore/studio-ui-bundle/modules/app'
import { AssetPilotDashboard } from './components/asset-pilot-dashboard'

export const AssetPilotModule: AbstractModule = {
  onInit: (): void => {
    // Register widget first (before nav item references it)
    const widgetRegistryService = container.get<WidgetRegistry>(serviceIds.widgetManager)
    widgetRegistryService.registerWidget({
      name: 'asset-pilot-dashboard',
      component: AssetPilotDashboard
    })

    // Register nav item
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
  }
}
