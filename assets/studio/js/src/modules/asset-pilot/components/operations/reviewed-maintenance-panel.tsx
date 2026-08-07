import React, { useId } from 'react'

interface ReviewedMaintenancePanelProps {
  title: string
  description: string
  limitLabel: string
  limitHint: string
  limitInvalid: string
  reviewLabel: string
  reviewingLabel: string
  applyLabel: string
  applyingLabel: string
  limit: string
  limitIsValid: boolean
  action: 'preview' | 'apply' | null
  canApply: boolean
  error: string | null
  onLimitChange: (value: string) => void
  onPreview: () => void
  onApply: () => void
  children?: React.ReactNode
}

export const ReviewedMaintenancePanel: React.FC<ReviewedMaintenancePanelProps> = props => {
  const titleId = useId()
  const limitHintId = useId()

  return (
    <section aria-labelledby={titleId} style={{ borderTop: '1px solid var(--ap-color-border-secondary)', paddingTop: 28 }}>
      <h4 id={titleId} style={titleStyle}>{props.title}</h4>
      <p style={descriptionStyle}>{props.description}</p>
      <div style={controlsStyle}>
        <label style={fieldStyle}>
          {props.limitLabel}
          <input
            type="number"
            min={1}
            max={1000}
            step={1}
            value={props.limit}
            disabled={props.action != null}
            aria-invalid={!props.limitIsValid}
            aria-describedby={limitHintId}
            onChange={event => props.onLimitChange(event.target.value)}
            style={{ ...inputStyle, borderColor: props.limitIsValid ? 'var(--ap-color-border)' : 'var(--ap-color-error)' }}
          />
        </label>
        <button type="button" onClick={props.onPreview} disabled={props.action != null || !props.limitIsValid} style={secondaryButtonStyle}>
          {props.action === 'preview' ? props.reviewingLabel : props.reviewLabel}
        </button>
        {props.canApply && (
          <button type="button" onClick={props.onApply} disabled={props.action != null} style={primaryButtonStyle}>
            {props.action === 'apply' ? props.applyingLabel : props.applyLabel}
          </button>
        )}
      </div>
      <p id={limitHintId} style={props.limitIsValid ? hintStyle : errorStyle}>
        {props.limitIsValid ? props.limitHint : props.limitInvalid}
      </p>
      {props.error != null && <p role="alert" style={errorStyle}>{props.error}</p>}
      {props.children}
    </section>
  )
}

const titleStyle: React.CSSProperties = { margin: '0 0 4px', fontSize: 14, fontWeight: 600 }
const descriptionStyle: React.CSSProperties = { margin: '0 0 12px', fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }
const controlsStyle: React.CSSProperties = { display: 'flex', alignItems: 'flex-end', gap: 8, flexWrap: 'wrap' }
const fieldStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, fontSize: 'var(--ap-font-size)', color: 'var(--ap-color-text-secondary)' }
const inputStyle: React.CSSProperties = { width: 100, padding: '5px 8px', border: '1px solid var(--ap-color-border)', borderRadius: 4, fontSize: 'var(--ap-font-size)' }
const hintStyle: React.CSSProperties = { margin: '5px 0 0', color: 'var(--ap-color-text-secondary)', fontSize: 'var(--ap-font-size)' }
const errorStyle: React.CSSProperties = { margin: '8px 0 0', color: 'var(--ap-color-error-text-active)', fontSize: 'var(--ap-font-size)' }
const secondaryButtonStyle: React.CSSProperties = { padding: '6px 14px', border: '1px solid var(--ap-color-border)', borderRadius: 6, background: 'var(--ap-color-bg-container)', color: 'var(--ap-color-text-secondary)', cursor: 'pointer', fontSize: 'var(--ap-font-size)' }
const primaryButtonStyle: React.CSSProperties = { padding: '6px 14px', border: '1px solid var(--ap-color-warning)', borderRadius: 6, background: 'var(--ap-color-warning-bg)', color: 'var(--ap-color-warning-text-active)', cursor: 'pointer', fontSize: 'var(--ap-font-size)', fontWeight: 500 }
