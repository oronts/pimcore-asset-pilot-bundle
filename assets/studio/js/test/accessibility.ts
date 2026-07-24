import axe from 'axe-core'
import { expect } from 'vitest'

export async function expectNoAccessibilityViolations(container: Element): Promise<void> {
  const results = await axe.run(container, {
    rules: {
      'color-contrast': { enabled: false },
    },
  })

  expect(results.violations).toEqual([])
}
