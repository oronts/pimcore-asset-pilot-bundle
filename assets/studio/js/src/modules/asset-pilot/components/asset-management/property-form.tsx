import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'

interface PropertyFormProps {
  assetIds: number[]
  onDone: (result: string) => void
  onCancel: () => void
}

export const PropertyForm: React.FC<PropertyFormProps> = ({ assetIds, onDone, onCancel }) => {
  const { t } = useTranslation()
  const [name, setName] = useState('')
  const [type, setType] = useState('text')
  const [value, setValue] = useState<string>('')
  const [boolValue, setBoolValue] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  const handleSubmit = async (): Promise<void> => {
    if (!name.trim()) return
    setSubmitting(true)
    try {
      const data = type === 'bool' ? boolValue : value
      const result = await assetPilotApi.bulkSetProperty(assetIds, name.trim(), type, data)
      onDone(`Updated: ${result.updated ?? 0}, Failed: ${result.failed}`)
    } catch (e: unknown) {
      onDone(`Error: ${e instanceof Error ? e.message : 'Unknown error'}`)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div style={{ padding: '8px 0' }}>
      <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 6, color: '#1a1a1a' }}>
        {t('asset-pilot.management.property-title')}
      </div>

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <label style={labelStyle}>{t('asset-pilot.management.property-name')}</label>
          <input
            type="text"
            value={name}
            onChange={e => setName(e.target.value)}
            placeholder="status"
            style={{ ...inputStyle, width: 120 }}
          />
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <label style={labelStyle}>{t('asset-pilot.management.property-type')}</label>
          <select value={type} onChange={e => setType(e.target.value)} style={inputStyle}>
            <option value="text">{t('asset-pilot.management.property-type-text')}</option>
            <option value="bool">{t('asset-pilot.management.property-type-bool')}</option>
            <option value="select">{t('asset-pilot.management.property-type-select')}</option>
          </select>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <label style={labelStyle}>{t('asset-pilot.management.property-value')}</label>
          {type === 'bool' ? (
            <label style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 12, padding: '5px 0' }}>
              <input type="checkbox" checked={boolValue} onChange={e => setBoolValue(e.target.checked)} />
              {boolValue ? t('asset-pilot.common.yes') : t('asset-pilot.common.no')}
            </label>
          ) : (
            <input
              type="text"
              value={value}
              onChange={e => setValue(e.target.value)}
              placeholder={type === 'select' ? 'option1,option2' : 'value'}
              style={{ ...inputStyle, width: 140 }}
            />
          )}
        </div>

        <button
          onClick={handleSubmit}
          disabled={submitting || !name.trim()}
          style={confirmBtnStyle}
        >
          {submitting ? t('asset-pilot.management.property-updating') : t('asset-pilot.management.property-confirm')}
        </button>
        <button onClick={onCancel} disabled={submitting} style={cancelBtnStyle}>
          {t('asset-pilot.common.cancel')}
        </button>
      </div>
    </div>
  )
}

const labelStyle: React.CSSProperties = { fontSize: 11, color: '#8c8c8c', fontWeight: 500 }
const inputStyle: React.CSSProperties = { padding: '5px 10px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 12, outline: 'none' }
const confirmBtnStyle: React.CSSProperties = { padding: '5px 12px', border: '1px solid #91caff', borderRadius: 4, background: '#e6f4ff', color: '#0958d9', cursor: 'pointer', fontSize: 12, fontWeight: 500, alignSelf: 'flex-end' }
const cancelBtnStyle: React.CSSProperties = { padding: '5px 12px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff', color: '#595959', cursor: 'pointer', fontSize: 12, alignSelf: 'flex-end' }
