import type { GlobalToken } from 'antd'
import { describe, expect, it } from 'vitest'
import { assetPilotThemeVariables } from './theme-variables'

describe('assetPilotThemeVariables', () => {
  it('keeps neutral contrast text and exposes distinct semantic active text', () => {
    const variables = assetPilotThemeVariables({
      colorText: '#262626',
      colorSuccessText: '#52c41a',
      colorSuccessTextActive: '#135200',
      colorWarningText: '#faad14',
      colorWarningTextActive: '#613400',
      colorErrorText: '#ff4d4f',
      colorErrorTextActive: '#820014',
    } as GlobalToken)

    expect(variables['--ap-color-success-text']).toBe('#262626')
    expect(variables['--ap-color-warning-text']).toBe('#262626')
    expect(variables['--ap-color-error-text']).toBe('#262626')
    expect(variables['--ap-color-success-text-active']).toBe('#135200')
    expect(variables['--ap-color-warning-text-active']).toBe('#613400')
    expect(variables['--ap-color-error-text-active']).toBe('#820014')
  })
})
