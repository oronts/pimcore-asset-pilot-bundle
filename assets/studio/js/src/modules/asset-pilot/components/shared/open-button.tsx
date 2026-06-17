import React, { useState } from 'react'
import { useToast } from '../../hooks/use-toast'

type ElementType = 'asset' | 'data-object'

interface PimcoreStudioApi {
  element?: {
    openAsset: (id: number) => Promise<void>
    openDataObject: (id: number) => Promise<void>
  }
}

interface OpenButtonProps {
  id: number
  type: ElementType
}

export const OpenButton: React.FC<OpenButtonProps> = ({ id, type }) => {
  const toast = useToast()
  const [opening, setOpening] = useState(false)

  const handleClick = async (): Promise<void> => {
    if (opening) return
    setOpening(true)

    try {
      const api = (window as unknown as { PimcoreStudio?: PimcoreStudioApi }).PimcoreStudio
      if (api?.element != null) {
        if (type === 'asset') {
          await api.element.openAsset(id)
        } else {
          await api.element.openDataObject(id)
        }
      } else {
        toast.warning(`Pimcore Studio API not available — cannot open ${type} #${id}`)
      }
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e)
      toast.error(`Failed to open ${type} #${id}: ${msg}`)
    } finally {
      setOpening(false)
    }
  }

  return (
    <button
      onClick={() => { void handleClick() }}
      disabled={opening}
      style={{ ...linkStyle, opacity: opening ? 0.5 : 1 }}
      title={`Open ${type} #${id}`}
    >
      {id}
    </button>
  )
}

const linkStyle: React.CSSProperties = {
  background: 'none',
  border: 'none',
  color: '#1677ff',
  cursor: 'pointer',
  padding: 0,
  fontSize: 'inherit',
  fontWeight: 500,
  textDecoration: 'underline',
  textUnderlineOffset: 2,
}
