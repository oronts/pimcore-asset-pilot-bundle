import { describe, expect, it } from 'vitest'
import { ACTOR_TYPE_KEYS, BUILTIN_HEALTH_CHECK_KEYS, CONFIDENCE_LEVEL_KEYS, DELIVERY_OUTCOME_KEYS, DISPOSITION_OUTCOME_KEYS, DRIFT_ELIGIBILITY_KEYS, HEAL_HISTORY_STATUS_KEYS, HEAL_OUTCOME_KEYS, HEALTH_STATUS_KEYS, OPERATION_RUN_ITEM_STATUS_KEYS, OPERATION_RUN_STATUS_KEYS, OPERATION_STAT_KEYS, RECOVERY_KIND_KEYS, UNDO_HEAL_REASON_KEYS } from './backend-contract'
import { de } from './de'
import { en } from './en'

function placeholders(value: string): string[] {
  return [...value.matchAll(/{{\s*([^},\s]+)/g)].map(match => match[1]).sort()
}

describe('translations', () => {
  it('has the same non-empty keys in English and German', () => {
    expect(Object.keys(de).sort()).toEqual(Object.keys(en).sort())
    expect(Object.values(en).every(value => value.trim() !== '')).toBe(true)
    expect(Object.values(de).every(value => value.trim() !== '')).toBe(true)
  })

  it('uses matching interpolation variables for every language', () => {
    for (const key of Object.keys(en)) {
      expect(placeholders(de[key]), key).toEqual(placeholders(en[key]))
    }
  })

  it('localizes every backend operation status in both locales', () => {
    for (const status of OPERATION_STAT_KEYS) {
      const key = `asset-pilot.operations.stat.${status}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every built-in health check in both locales', () => {
    for (const check of BUILTIN_HEALTH_CHECK_KEYS) {
      const key = `asset-pilot.health.check.${check}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every duplicate-merge disposition outcome in both locales', () => {
    for (const outcome of DISPOSITION_OUTCOME_KEYS) {
      const key = `asset-pilot.duplicates.outcome.${outcome}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every operation-run and run-item status in both locales', () => {
    for (const status of [...OPERATION_RUN_STATUS_KEYS, ...OPERATION_RUN_ITEM_STATUS_KEYS]) {
      const key = `asset-pilot.operation-run.status.${status}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every undo-heal reason in both locales', () => {
    for (const reason of UNDO_HEAL_REASON_KEYS) {
      const key = `asset-pilot.integrity.history.reason.${reason}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every recovery kind in both locales', () => {
    for (const kind of RECOVERY_KIND_KEYS) {
      const key = `asset-pilot.recovery.kind.${kind}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every confidence level in both locales', () => {
    for (const level of CONFIDENCE_LEVEL_KEYS) {
      const key = `asset-pilot.confidence.${level.replace(/_/g, '-')}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every heal-history status in both locales', () => {
    for (const status of HEAL_HISTORY_STATUS_KEYS) {
      const key = `asset-pilot.integrity.history.status.${status}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every delivery-retry outcome in both locales', () => {
    for (const outcome of DELIVERY_OUTCOME_KEYS) {
      const key = `asset-pilot.delivery-retry.outcome.${outcome}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every health status in both locales', () => {
    for (const status of HEALTH_STATUS_KEYS) {
      const key = `asset-pilot.health.status.${status}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every integrity heal outcome in both locales', () => {
    for (const outcome of HEAL_OUTCOME_KEYS) {
      const key = `asset-pilot.integrity.outcome.${outcome}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every audit actor type in both locales', () => {
    for (const actor of ACTOR_TYPE_KEYS) {
      const key = `asset-pilot.audit.actor-${actor}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every drift eligibility in both locales', () => {
    for (const eligibility of DRIFT_ELIGIBILITY_KEYS) {
      const key = `asset-pilot.drift.eligibility-${eligibility}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every operation status under the audit status prefix in both locales', () => {
    for (const status of OPERATION_STAT_KEYS) {
      const key = `asset-pilot.status.${status}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })

  it('localizes every operation kind under the audit operation prefix in both locales', () => {
    for (const kind of RECOVERY_KIND_KEYS) {
      const key = `asset-pilot.audit.operation-${kind}`
      expect(en[key], key).toBeTypeOf('string')
      expect(de[key], key).toBeTypeOf('string')
    }
  })
})
