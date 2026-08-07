import type React from 'react'
import type { GlobalToken } from 'antd'

type ThemeVariables = React.CSSProperties & Record<`--ap-${string}`, string | number>

export function assetPilotThemeVariables(token: GlobalToken): ThemeVariables {
  return {
    '--ap-font-size': `${token.fontSize}px`,
    '--ap-color-text': token.colorText,
    '--ap-color-text-secondary': token.colorTextSecondary,
    '--ap-color-text-tertiary': token.colorTextTertiary,
    '--ap-color-text-light-solid': token.colorTextLightSolid,
    '--ap-color-bg-container': token.colorBgContainer,
    '--ap-color-bg-elevated': token.colorBgElevated,
    '--ap-color-fill-alter': token.colorFillAlter,
    '--ap-color-fill-secondary': token.colorFillSecondary,
    '--ap-color-border': token.colorBorder,
    '--ap-color-border-secondary': token.colorBorderSecondary,
    '--ap-color-primary': token.colorPrimary,
    '--ap-color-primary-active': token.colorPrimaryActive,
    '--ap-color-primary-border': token.colorPrimaryBorder,
    '--ap-color-primary-bg': token.colorPrimaryBg,
    '--ap-color-info': token.colorInfo,
    '--ap-color-info-text': token.colorInfoText,
    '--ap-color-info-border': token.colorInfoBorder,
    '--ap-color-info-bg': token.colorInfoBg,
    '--ap-color-success': token.colorSuccess,
    '--ap-color-success-text': token.colorText,
    '--ap-color-success-text-active': token.colorSuccessTextActive,
    '--ap-color-success-border': token.colorSuccessBorder,
    '--ap-color-success-border-hover': token.colorSuccessBorderHover,
    '--ap-color-success-bg': token.colorSuccessBg,
    '--ap-color-warning': token.colorWarning,
    '--ap-color-warning-text': token.colorText,
    '--ap-color-warning-text-active': token.colorWarningTextActive,
    '--ap-color-warning-border': token.colorWarningBorder,
    '--ap-color-warning-border-hover': token.colorWarningBorderHover,
    '--ap-color-warning-bg': token.colorWarningBg,
    '--ap-color-error': token.colorError,
    '--ap-color-error-text': token.colorText,
    '--ap-color-error-text-active': token.colorErrorTextActive,
    '--ap-color-error-border': token.colorErrorBorder,
    '--ap-color-error-border-hover': token.colorErrorBorderHover,
    '--ap-color-error-bg': token.colorErrorBg,
    '--ap-color-bg-mask': token.colorBgMask,
    '--ap-box-shadow': token.boxShadow,
    '--ap-box-shadow-secondary': token.boxShadowSecondary,
  }
}
