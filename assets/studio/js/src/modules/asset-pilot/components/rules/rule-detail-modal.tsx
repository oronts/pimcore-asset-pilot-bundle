import React from 'react'
import { useTranslation } from 'react-i18next'
import { useRuleDetail } from '../../hooks/use-asset-pilot-api'
import { StrategyTag, StatusTag } from '../shared/status-tag'

interface RuleDetailModalProps {
  ruleName: string
  onClose: () => void
}

export const RuleDetailModal: React.FC<RuleDetailModalProps> = ({ ruleName, onClose }) => {
  const { t } = useTranslation()
  const { data: rule, loading, error } = useRuleDetail(ruleName)

  return (
    <div style={overlayStyle} onClick={onClose}>
      <div style={modalStyle} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 600 }}>{t('asset-pilot.rule-detail.title', { name: ruleName })}</h3>
          <button onClick={onClose} style={closeBtnStyle}>&times;</button>
        </div>

        {loading && <p style={{ color: '#8c8c8c' }}>{t('asset-pilot.common.loading')}</p>}
        {error != null && <p style={{ color: '#ff4d4f' }}>{t('asset-pilot.common.error', { message: error })}</p>}

        {rule != null && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            <Section title={t('asset-pilot.rule-detail.configuration')}>
              <Row label={t('asset-pilot.columns.class')} value={rule.class} />
              <Row label={t('asset-pilot.columns.strategy')} value={<StrategyTag strategy={rule.strategy} />} />
              <Row label={t('asset-pilot.columns.target-path')} value={<code style={{ fontSize: 12 }}>{rule.targetPath}</code>} />
              <Row label={t('asset-pilot.rules.fields')} value={rule.fields?.join(', ') || t('asset-pilot.rules.all-fields')} />
              <Row label={t('asset-pilot.columns.priority')} value={String(rule.priority)} />
              <Row label={t('asset-pilot.columns.enabled')} value={rule.enabled ? t('asset-pilot.common.yes') : t('asset-pilot.common.no')} />
              {rule.condition != null && (
                <Row label={t('asset-pilot.rules.condition')} value={<code style={{ fontSize: 12 }}>{rule.condition}</code>} />
              )}
            </Section>

            {rule.filters != null && Object.keys(rule.filters).length > 0 && (
              <Section title={t('asset-pilot.rules.filters')}>
                {Object.entries(rule.filters).map(([key, val]) => (
                  <Row key={key} label={key} value={<code style={{ fontSize: 12 }}>{JSON.stringify(val)}</code>} />
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
    <h4 style={{ margin: '0 0 10px', fontSize: 13, fontWeight: 600, color: '#8c8c8c', textTransform: 'uppercase', letterSpacing: 0.5 }}>
      {title}
    </h4>
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>{children}</div>
  </div>
)

const Row: React.FC<{ label: string; value: React.ReactNode }> = ({ label, value }) => (
  <div style={{ display: 'flex', gap: 12, fontSize: 13 }}>
    <span style={{ minWidth: 100, color: '#8c8c8c', fontWeight: 500 }}>{label}</span>
    <span>{value}</span>
  </div>
)

const overlayStyle: React.CSSProperties = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.3)', display: 'flex',
  alignItems: 'center', justifyContent: 'center', zIndex: 1000,
}

const modalStyle: React.CSSProperties = {
  background: '#fff', borderRadius: 12, padding: 24, width: 560, maxHeight: '80vh',
  overflow: 'auto', boxShadow: '0 8px 32px rgba(0,0,0,0.12)',
}

const closeBtnStyle: React.CSSProperties = {
  border: 'none', background: 'none', fontSize: 22, cursor: 'pointer', color: '#8c8c8c',
  width: 32, height: 32, display: 'flex', alignItems: 'center', justifyContent: 'center',
}
