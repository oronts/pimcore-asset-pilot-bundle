import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import { findChunkBudgetViolations } from './chunk-budget.mjs'

const temporaryDirectories = []

afterEach(() => {
  for (const directory of temporaryDirectories.splice(0)) {
    fs.rmSync(directory, { recursive: true, force: true })
  }
})

describe('chunk budget', () => {
  it('reports the exact JavaScript chunk that exceeds its raw budget', () => {
    const buildPath = fs.mkdtempSync(path.join(os.tmpdir(), 'asset-pilot-budget-'))
    temporaryDirectories.push(buildPath)
    fs.mkdirSync(path.join(buildPath, 'static', 'js'), { recursive: true })
    fs.writeFileSync(path.join(buildPath, 'static', 'js', 'large.js'), 'x'.repeat(101))

    expect(findChunkBudgetViolations(buildPath, {
      maxJavaScriptChunkBytes: 100,
      maxJavaScriptChunkGzipBytes: 100,
    })).toEqual(['static/js/large.js: 101 raw bytes exceeds 100'])
  })
})
