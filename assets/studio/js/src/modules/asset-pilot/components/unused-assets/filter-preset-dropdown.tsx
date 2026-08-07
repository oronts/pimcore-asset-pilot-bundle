import React, { useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useFilterPresets } from '../../hooks/use-filter-presets'
import { useToast } from '../../hooks/use-toast'
import type { UnusedAssetFilters } from '../../types'

interface FilterPresetDropdownProps {
  currentFilters: UnusedAssetFilters
  onLoad: (filters: UnusedAssetFilters) => void
}

export const FilterPresetDropdown: React.FC<FilterPresetDropdownProps> = ({ currentFilters, onLoad }) => {
  const { t } = useTranslation()
  const toast = useToast()
  const { presets, savePreset, deletePreset, loadPreset } = useFilterPresets<UnusedAssetFilters>('asset-pilot-filter-presets-unused')
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')
  const dropdownId = useId()

  const handleSave = (): void => {
    if (name.trim() === '') return
    const storedFilters = { ...currentFilters }
    delete storedFilters.page
    delete storedFilters.limit
    savePreset(name.trim(), storedFilters)
    toast.success(t('asset-pilot.presets.saved'))
    setName('')
  }

  const handleLoad = (presetName: string): void => {
    const filters = loadPreset(presetName)
    if (filters != null) {
      onLoad({ ...filters, page: 1, limit: currentFilters.limit })
      setOpen(false)
    }
  }

  const handleDelete = (presetName: string): void => {
    deletePreset(presetName)
    toast.info(t('asset-pilot.presets.deleted'))
  }

  return (
    <div style={{ position: 'relative', alignSelf: 'flex-end' }}>
      <button type="button" aria-expanded={open} aria-controls={dropdownId} onClick={() => setOpen(!open)} style={btnStyle}>
        {t('asset-pilot.presets.button')}
      </button>

      {open && (
        <>
          <div role="presentation" style={{ position: 'fixed', inset: 0, zIndex: 99 }} onClick={() => setOpen(false)} />
          <div id={dropdownId} role="group" aria-label={t('asset-pilot.presets.button')} style={dropdownStyle}>
            {presets.length === 0 && (
              <p style={{ margin: 0, padding: '8px 0', color: 'var(--ap-color-text-secondary)', fontSize: 'var(--ap-font-size)' }}>
                {t('asset-pilot.presets.empty')}
              </p>
            )}

            {presets.map(p => (
              <div key={p.name} style={presetRowStyle}>
                <button type="button" onClick={() => handleLoad(p.name)} style={loadBtnStyle}>{p.name}</button>
                <button type="button" aria-label={`${t('asset-pilot.presets.delete')}: ${p.name}`} onClick={() => handleDelete(p.name)} style={delBtnStyle}>&times;</button>
              </div>
            ))}

            <div style={{ borderTop: '1px solid var(--ap-color-border-secondary)', marginTop: 4, paddingTop: 8, display: 'flex', gap: 4 }}>
              <input
                type="text"
                value={name}
                onChange={e => setName(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && handleSave()}
                aria-label={t('asset-pilot.presets.save-placeholder')}
                placeholder={t('asset-pilot.presets.save-placeholder')}
                style={inputStyle}
              />
              <button type="button" onClick={handleSave} disabled={name.trim() === ''} style={saveBtnStyle}>
                {t('asset-pilot.presets.save')}
              </button>
            </div>
          </div>
        </>
      )}
    </div>
  )
}

const btnStyle: React.CSSProperties = {
  padding: '5px 12px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)',
  cursor: 'pointer', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)',
}
const dropdownStyle: React.CSSProperties = {
  position: 'absolute', top: '100%', right: 0, marginTop: 4, width: 240,
  maxWidth: 'calc(100vw - 32px)',
  background: 'var(--ap-color-bg-container)', border: '1px solid var(--ap-color-border)', borderRadius: 8,
  boxShadow: 'var(--ap-box-shadow-secondary)', padding: 12, zIndex: 100,
}
const presetRowStyle: React.CSSProperties = {
  display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '4px 0',
}
const loadBtnStyle: React.CSSProperties = {
  border: 'none', background: 'none', cursor: 'pointer', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-primary)',
  fontWeight: 500, padding: 0, textAlign: 'left',
}
const delBtnStyle: React.CSSProperties = {
  border: 'none', background: 'none', cursor: 'pointer', fontSize: 16, color: 'var(--ap-color-text-secondary)', padding: 0, lineHeight: 1,
}
const inputStyle: React.CSSProperties = {
  flex: 1, padding: '4px 8px', border: '1px solid var(--ap-color-border)', borderRadius: 4, fontSize: 'var(--ap-font-size)', outline: 'none',
}
const saveBtnStyle: React.CSSProperties = {
  padding: '4px 10px', border: '1px solid var(--ap-color-primary)', borderRadius: 4, background: 'var(--ap-color-primary-bg)',
  color: 'var(--ap-color-primary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500,
}
