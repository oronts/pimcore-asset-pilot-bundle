import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { assetPilotApi } from '../../services/api'
import { useRules } from '../../hooks/use-asset-pilot-api'
import type { BulkPreviewResponse } from '../../types'
import { OpenButton } from '../shared/open-button'
import { useToast } from '../../hooks/use-toast'
import { usePermissions } from '../../hooks/use-permissions'
import { ConfirmDialog } from '../shared/confirm-dialog'
import { pageNumbers } from '../../utils/format'

export const BulkOrganizeForm: React.FC = () => {
  const { t } = useTranslation()
  const toast = useToast()
  const { operate } = usePermissions()
  const { data: rules } = useRules()
  const [className, setClassName] = useState('')
  const [batchSize, setBatchSize] = useState('50')
  const [loading, setLoading] = useState(false)
  const [previewLoading, setPreviewLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [previewData, setPreviewData] = useState<BulkPreviewResponse | null>(null)
  const [previewPage, setPreviewPage] = useState(1)
  const [showConfirm, setShowConfirm] = useState(false)

  const classNames = [...new Set((rules ?? []).map(r => r.class))]

  const fetchPreview = async (page: number): Promise<void> => {
    if (className === '') {
      setError(t('asset-pilot.operations.select-a-class'))
      return
    }

    setPreviewLoading(true)
    setError(null)

    try {
      const res = await assetPilotApi.organizeBulkPreview(className, page, 50)
      setPreviewData(res)
      setPreviewPage(page)
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Preview failed')
    } finally {
      setPreviewLoading(false)
    }
  }

  const handlePreview = async (): Promise<void> => {
    setPreviewData(null)
    setPreviewPage(1)
    await fetchPreview(1)
  }

  const handleBulkOrganize = async (): Promise<void> => {
    setLoading(true)
    setError(null)

    try {
      const res = await assetPilotApi.organizeBulk({
        className,
        async: true,
        batchSize: parseInt(batchSize, 10) || 50,
      })
      toast.success(t('asset-pilot.operations.bulk-queued', {
        objects: res.objectCount ?? 0,
        batches: res.batchCount ?? 0,
      }))
      setPreviewData(null)
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Bulk operation failed')
    } finally {
      setLoading(false)
      setShowConfirm(false)
    }
  }

  return (
    <div>
      <h4 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 600 }}>{t('asset-pilot.operations.bulk-title')}</h4>

      <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12, flexWrap: 'wrap' }}>
        <select
          value={className}
          onChange={e => { setClassName(e.target.value); setPreviewData(null); setError(null) }}
          style={selectStyle}
        >
          <option value="">{t('asset-pilot.operations.select-class')}</option>
          {classNames.map(c => <option key={c} value={c}>{c}</option>)}
        </select>

        <input
          type="number"
          value={batchSize}
          onChange={e => setBatchSize(e.target.value)}
          placeholder={t('asset-pilot.operations.batch-size')}
          style={{ ...inputStyle, width: 100 }}
          min={1}
          max={500}
        />

        <button
          onClick={() => { void handlePreview() }}
          disabled={previewLoading || className === ''}
          style={previewBtnStyle}
        >
          {previewLoading ? t('asset-pilot.operations.previewing') : t('asset-pilot.operations.preview')}
        </button>
      </div>

      {error != null && <p style={{ color: '#ff4d4f', fontSize: 13 }}>{error}</p>}

      {previewData != null && (
        <div style={{ marginBottom: 12 }}>
          <p style={{ fontSize: 12, color: '#8c8c8c', marginBottom: 8 }}>
            {t('asset-pilot.operations.preview-count', { count: previewData.total })}
          </p>

          {previewData.objects.length > 0 && (
            <>
              <div style={{ overflowX: 'auto', maxHeight: 400, overflowY: 'auto', marginBottom: 12 }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                  <thead>
                    <tr style={{ borderBottom: '2px solid #f0f0f0' }}>
                      <th style={thStyle}>{t('asset-pilot.columns.id')}</th>
                      <th style={thStyle}>{t('asset-pilot.columns.object-name')}</th>
                      <th style={thStyle}>{t('asset-pilot.columns.class')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {previewData.objects.map(obj => (
                      <tr key={obj.id} style={{ borderBottom: '1px solid #f5f5f5' }}>
                        <td style={tdStyle}><OpenButton id={obj.id} type="data-object" /></td>
                        <td style={{ ...tdStyle, fontWeight: 500 }}>{obj.key}</td>
                        <td style={tdStyle}>{obj.className}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {previewData.pages > 1 && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 4, marginBottom: 12 }}>
                  <button
                    onClick={() => { void fetchPreview(previewPage - 1) }}
                    disabled={previewPage <= 1 || previewLoading}
                    style={pageBtnStyle}
                  >
                    {t('asset-pilot.common.prev')}
                  </button>
                  {pageNumbers(previewPage, previewData.pages).map(p => (
                    <button
                      key={p}
                      onClick={() => { void fetchPreview(p) }}
                      disabled={previewLoading}
                      style={{
                        ...pageBtnStyle,
                        background: p === previewPage ? '#1677ff' : '#fff',
                        color: p === previewPage ? '#fff' : '#595959',
                        fontWeight: p === previewPage ? 600 : 400,
                      }}
                    >
                      {p}
                    </button>
                  ))}
                  <button
                    onClick={() => { void fetchPreview(previewPage + 1) }}
                    disabled={previewPage >= previewData.pages || previewLoading}
                    style={pageBtnStyle}
                  >
                    {t('asset-pilot.common.next')}
                  </button>
                  <span style={{ fontSize: 11, color: '#8c8c8c', marginLeft: 8 }}>
                    {t('asset-pilot.common.page-info', { page: previewPage, pages: previewData.pages })}
                  </span>
                </div>
              )}

              {operate && (
                <button onClick={() => setShowConfirm(true)} disabled={loading} style={warnBtnStyle}>
                  {t('asset-pilot.operations.organize-all')}
                </button>
              )}
            </>
          )}
        </div>
      )}

      {showConfirm && (
        <ConfirmDialog
          title={t('asset-pilot.confirm.organize-title')}
          description={t('asset-pilot.confirm.organize-description')}
          confirmLabel={t('asset-pilot.common.confirm')}
          variant="warning"
          loading={loading}
          onConfirm={() => { void handleBulkOrganize() }}
          onCancel={() => setShowConfirm(false)}
        />
      )}
    </div>
  )
}

const selectStyle: React.CSSProperties = {
  padding: '6px 12px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 13, outline: 'none', minWidth: 180,
}
const inputStyle: React.CSSProperties = { padding: '6px 12px', border: '1px solid #d9d9d9', borderRadius: 6, fontSize: 13, outline: 'none' }
const previewBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #1677ff', borderRadius: 6, background: '#e6f4ff', color: '#1677ff',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const warnBtnStyle: React.CSSProperties = {
  padding: '6px 16px', border: '1px solid #fa8c16', borderRadius: 6, background: '#fff7e6', color: '#fa8c16',
  cursor: 'pointer', fontSize: 13, fontWeight: 500,
}
const pageBtnStyle: React.CSSProperties = {
  padding: '4px 10px', border: '1px solid #d9d9d9', borderRadius: 4, background: '#fff', color: '#595959',
  cursor: 'pointer', fontSize: 12,
}
const thStyle: React.CSSProperties = { textAlign: 'left', padding: '6px', fontSize: 11, color: '#8c8c8c', fontWeight: 500 }
const tdStyle: React.CSSProperties = { padding: '6px' }
