import React from 'react'
import { createInstance, type i18n as I18nInstance } from 'i18next'
import { render, type RenderOptions, type RenderResult } from '@testing-library/react'
import { I18nextProvider } from 'react-i18next'
import { de } from '../src/i18n/de'
import { en } from '../src/i18n/en'
import { ToastProvider } from '../src/modules/asset-pilot/components/shared/toast/toast-context'

interface TestRenderOptions extends Omit<RenderOptions, 'wrapper'> {
  language?: 'de' | 'en'
}

interface TestRenderResult extends RenderResult {
  i18n: I18nInstance
}

export function renderWithI18n(ui: React.ReactElement, options: TestRenderOptions = {}): TestRenderResult {
  const { language = 'en', ...renderOptions } = options
  const i18n = createInstance()
  void i18n.init({
    resources: {
      de: { translation: de },
      en: { translation: en },
    },
    lng: language,
    fallbackLng: 'en',
    initImmediate: false,
    interpolation: { escapeValue: false },
  })
  const Wrapper = ({ children }: { children: React.ReactNode }): React.ReactElement => (
    <I18nextProvider i18n={i18n}>
      <ToastProvider>{children}</ToastProvider>
    </I18nextProvider>
  )

  return {
    i18n,
    ...render(ui, { wrapper: Wrapper, ...renderOptions }),
  }
}
