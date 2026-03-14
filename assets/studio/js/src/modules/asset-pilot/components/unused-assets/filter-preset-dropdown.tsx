import React, { useState } from 'react'
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

  const handleSave = (): void => {
    if (name.trim() === '') return
    const { page, limit, ...rest } = currentFilters
    savePreset(name.trim(), rest as UnusedAssetFilters)
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
      <button onClick={() => setOpen(!open)} style={btnStyle}>
        {t('asset-pilot.presets.button')}
      </button>

      {open && (
        <>
          <div style={{ position: 'fixed', inset: 0, zIndex: 99 }} onClick={() => setOpen(false)} />
          <div style={dropdownStyle}>
            {presets.length === 0 && (
              <p style={{ margin: 0, padding: '8px 0', color: '#8c8c8c', fontSize: 12 }}>
                {t('asset-pilot.presets.empty')}
              </p>
            )}

            {presets.map(p => (
              <div key={p.name} style={presetRowStyle}>
                <button onClick={() => handleLoad(p.name)} style={loadBtnStyle}>{p.name}</button>
                <button onClick={() => handleDelete(p.name)} style={delBtnStyle}>&times;</button>
              </div>
            ))}

            <div style={{ borderTop: '1px solid #f0f0f0', marginTop: 4, paddingTop: 8, display: 'flex', gap: 4 }}>
              <input
                type="text"
                value={name}
                onChange={e => setName(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && handleSave()}
                placeholder={t('asset-pilot.presets.save-placeholder')}
                style={inputStyle}
              />
              <button onClick={handleSave} disabled={name.trim() === ''} style={saveBtnStyle}>
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
  padding: '5px 12px', border: '1px solid #d9d9d9', borderRadius: 6, background: '#fff',
  cursor: 'pointer', fontSize: 12, color: '#595959',
}
const dropdownStyle: React.CSSProperties = {
  position: 'absolute', top: '100%', right: 0, marginTop: 4, width: 240,
  background: '#fff', border: '1px solid #d9d9d9', borderRadius: 8,
  boxShadow: '0 4px 16px rgba(0,0,0,0.1)', padding: 12, zIndex: 100,
}
const presetRowStyle: React.CSSProperties = {
  display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '4px 0',
}
const loadBtnStyle: React.CSSProperties = {
  border: 'none', background: 'none', cursor: 'pointer', fontSize: 12, color: '#1677ff',
  fontWeight: 500, padding: 0, textAlign: 'left',
}
const delBtnStyle: React.CSSProperties = {
  border: 'none', background: 'none', cursor: 'pointer', fontSize: 16, color: '#8c8c8c', padding: 0, lineHeight: 1,
}
const inputStyle: React.CSSProperties = {
  flex: 1, padding: '4px 8px', border: '1px solid #d9d9d9', borderRadius: 4, fontSize: 11, outline: 'none',
}
const saveBtnStyle: React.CSSProperties = {
  padding: '4px 10px', border: '1px solid #1677ff', borderRadius: 4, background: '#e6f4ff',
  color: '#1677ff', cursor: 'pointer', fontSize: 11, fontWeight: 500,
}
