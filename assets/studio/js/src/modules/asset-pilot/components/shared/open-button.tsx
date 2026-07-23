import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useElementHelper } from '@pimcore/studio-ui-bundle/modules/element'
import type { ElementType } from '@pimcore/studio-ui-bundle'
import { useToast } from '../../hooks/use-toast'

interface OpenButtonProps {
  id: number
  type: ElementType // asset folders open as 'asset'
  label?: string // a filename to render instead of the bare id
}

export const OpenButton: React.FC<OpenButtonProps> = ({ id, type, label }) => {
  const { t } = useTranslation()
  const toast = useToast()
  const { openElement } = useElementHelper()
  const [opening, setOpening] = useState(false)

  const handleClick = async (): Promise<void> => {
    if (opening) return
    setOpening(true)

    try {
      await openElement({ id, type })
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e)
      toast.error(t('asset-pilot.open.failed', { type, id, message: msg }))
    } finally {
      setOpening(false)
    }
  }

  return (
    <button
      onClick={() => { void handleClick() }}
      disabled={opening}
      style={{ ...linkStyle, opacity: opening ? 0.5 : 1 }}
      title={t('asset-pilot.open.title', { type, id })}
    >
      {label ?? id}
    </button>
  )
}

const linkStyle: React.CSSProperties = {
  background: 'none',
  border: 'none',
  color: 'var(--ap-color-primary)',
  cursor: 'pointer',
  padding: 0,
  fontSize: 'inherit',
  fontWeight: 500,
  textDecoration: 'underline',
  textUnderlineOffset: 2,
}
