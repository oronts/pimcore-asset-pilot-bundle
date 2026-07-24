import { describe, expect, it } from 'vitest'
import { BUILTIN_HEALTH_CHECK_KEYS, DISPOSITION_OUTCOME_KEYS, OPERATION_STAT_KEYS } from './backend-contract'
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
})
