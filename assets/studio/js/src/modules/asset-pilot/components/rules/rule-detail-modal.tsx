import React from 'react'
import { useTranslation } from 'react-i18next'
import { useRuleDetail } from '../../hooks/use-asset-pilot-api'
import { StrategyTag, StatusTag } from '../shared/status-tag'
import { useModalDismiss } from '../../hooks/use-modal-dismiss'
import { modalOverlayStyle, modalSurfaceStyle } from '../shared/modal-styles'

interface RuleDetailModalProps {
  ruleName: string
  onClose: () => void
}

export const RuleDetailModal: React.FC<RuleDetailModalProps> = ({ ruleName, onClose }) => {
  const { t } = useTranslation()
  const { data: rule, loading, error } = useRuleDetail(ruleName)
  const modalRef = useModalDismiss<HTMLDivElement>(onClose)

  return (
    <div role="presentation" style={modalOverlayStyle} onClick={event => { if (event.target === event.currentTarget) onClose() }}>
      <div ref={modalRef} role="dialog" aria-modal="true" aria-label={t('asset-pilot.rule-detail.title', { name: ruleName })} tabIndex={-1} style={modalStyle}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.rule-detail.title', { name: ruleName })}</h3>
          <button onClick={onClose} aria-label={t('asset-pilot.common.close')} style={closeBtnStyle}>&times;</button>
        </div>

        {loading && <p style={{ color: 'var(--ap-color-text-secondary)' }}>{t('asset-pilot.common.loading')}</p>}
        {error != null && <p role="alert" style={{ color: 'var(--ap-color-error-text-active)' }}>{t('asset-pilot.common.error', { message: error })}</p>}

        {rule != null && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            <Section title={t('asset-pilot.rule-detail.configuration')}>
              <Row label={t('asset-pilot.columns.class')} value={rule.class} />
              <Row label={t('asset-pilot.columns.strategy')} value={<StrategyTag strategy={rule.strategy} />} />
              <Row label={t('asset-pilot.columns.target-path')} value={<code style={{ fontSize: 'var(--ap-font-size)' }}>{rule.targetPath}</code>} />
              <Row label={t('asset-pilot.rules.fields')} value={rule.fields?.join(', ') || t('asset-pilot.rules.all-fields')} />
              <Row label={t('asset-pilot.columns.priority')} value={String(rule.priority)} />
              <Row label={t('asset-pilot.columns.enabled')} value={rule.enabled ? t('asset-pilot.common.yes') : t('asset-pilot.common.no')} />
              {rule.condition != null && (
                <Row label={t('asset-pilot.rules.condition')} value={<code style={{ fontSize: 'var(--ap-font-size)' }}>{rule.condition}</code>} />
              )}
            </Section>

            {rule.filters != null && Object.keys(rule.filters).length > 0 && (
              <Section title={t('asset-pilot.rules.filters')}>
                {Object.entries(rule.filters).map(([key, val]) => (
                  <Row key={key} label={key} value={<code style={{ fontSize: 'var(--ap-font-size)' }}>{JSON.stringify(val)}</code>} />
                ))}
              </Section>
            )}

            {rule.stats != null && Object.keys(rule.stats).length > 0 && (
              <Section title={t('asset-pilot.rule-detail.statistics')}>
                <div style={{ display: 'flex', gap: 16 }}>
                  {Object.entries(rule.stats).map(([status, count]) => (
                    <div key={status} style={{ textAlign: 'center' }}>
                      <StatusTag status={status} />
                      <p style={{ margin: '4px 0 0', fontSize: 18, fontWeight: 600 }}>{count}</p>
                    </div>
                  ))}
                </div>
              </Section>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

const Section: React.FC<{ title: string; children: React.ReactNode }> = ({ title, children }) => (
  <div>
    <h4 style={{ margin: '0 0 10px', fontSize: 13, fontWeight: 600, color: 'var(--ap-color-text-secondary)', textTransform: 'uppercase', letterSpacing: 0.5 }}>
      {title}
    </h4>
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>{children}</div>
  </div>
)

const Row: React.FC<{ label: string; value: React.ReactNode }> = ({ label, value }) => (
  <div style={{ display: 'flex', gap: 12, fontSize: 13 }}>
    <span style={{ minWidth: 100, color: 'var(--ap-color-text-secondary)', fontWeight: 500 }}>{label}</span>
    <span>{value}</span>
  </div>
)

const modalStyle: React.CSSProperties = {
  ...modalSurfaceStyle, width: 560, maxWidth: 'calc(100vw - 32px)', maxHeight: '80vh', overflow: 'auto',
}

const closeBtnStyle: React.CSSProperties = {
  border: 'none', background: 'none', fontSize: 22, cursor: 'pointer', color: 'var(--ap-color-text-secondary)',
  width: 32, height: 32, display: 'flex', alignItems: 'center', justifyContent: 'center',
}
