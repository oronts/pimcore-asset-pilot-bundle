import { type IAbstractPlugin } from '@pimcore/studio-ui-bundle'
import { AssetPilotModule } from './modules/asset-pilot'
import { registerTranslations } from './i18n'

if (module.hot !== undefined) {
  module.hot.accept()
}

export const AssetPilotPlugin: IAbstractPlugin = {
  name: 'oronts-asset-pilot-plugin',
  priority: 0,

  onInit: (): void => {
    registerTranslations()
  },

  onStartup: ({ moduleSystem }): void => {
    moduleSystem.registerModule(AssetPilotModule)
    console.log('Hello from Asset Pilot.')
  }
}
