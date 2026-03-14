import i18n from 'i18next'
import { en } from './en'
import { de } from './de'

export function registerTranslations(): void {
  i18n.addResourceBundle('en', 'translation', en, true, true)
  i18n.addResourceBundle('de', 'translation', de, true, true)
}
