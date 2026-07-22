import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import { computeStudioSourceHash } from './source-hash.mjs'

const temporaryDirectories = []

afterEach(() => {
  for (const directory of temporaryDirectories.splice(0)) {
    fs.rmSync(directory, { recursive: true, force: true })
  }
})

function studioFixture(files) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ap-source-hash-'))
  temporaryDirectories.push(dir)
  for (const [relative, content] of Object.entries(files)) {
    const target = path.join(dir, relative)
    fs.mkdirSync(path.dirname(target), { recursive: true })
    fs.writeFileSync(target, content)
  }
  return dir
}

const base = {
  'js/src/main.ts': 'export const a = 1\n',
  'js/src/mod/util.ts': 'export const b = 2\n',
  'rsbuild.config.ts': 'export default {}\n',
  'package.json': '{"version":"2.0.0"}\n',
}

describe('computeStudioSourceHash', () => {
  it('is stable for identical source and ignores node_modules and build output', () => {
    const one = studioFixture({ ...base, 'node_modules/dep/index.js': 'noise', 'js/src/.cache/x': 'noise' })
    const two = studioFixture({ ...base, 'node_modules/other/index.js': 'different noise' })

    expect(computeStudioSourceHash(one)).toBe(computeStudioSourceHash(two))
  })

  it('changes when a source file changes', () => {
    const before = studioFixture(base)
    const after = studioFixture({ ...base, 'js/src/main.ts': 'export const a = 2\n' })

    expect(computeStudioSourceHash(after)).not.toBe(computeStudioSourceHash(before))
  })

  it('changes when a source file is added or removed', () => {
    const withExtra = studioFixture({ ...base, 'js/src/extra.ts': 'export const c = 3\n' })

    expect(computeStudioSourceHash(withExtra)).not.toBe(computeStudioSourceHash(studioFixture(base)))
  })

  it('normalizes CRLF so line endings do not change the hash', () => {
    const lf = studioFixture(base)
    const crlf = studioFixture({ ...base, 'js/src/main.ts': 'export const a = 1\r\n' })

    expect(computeStudioSourceHash(crlf)).toBe(computeStudioSourceHash(lf))
  })
})
