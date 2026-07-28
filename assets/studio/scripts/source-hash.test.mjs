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
  'tsconfig.json': '{"compilerOptions":{"baseUrl":"."}}\n',
  'package.json': '{"version":"2.0.0"}\n',
  'package-lock.json': '{"lockfileVersion":3}\n',
  'scripts/manifest-assets.mjs': 'export const isValidBuildId = (id) => Boolean(id)\n',
  'scripts/publish-build.mjs': 'export const publish = () => 0\n',
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

  it('changes when the lockfile, tsconfig, or a build script changes (transitive build inputs)', () => {
    const baseHash = computeStudioSourceHash(studioFixture(base))
    const bumpedLock = studioFixture({ ...base, 'package-lock.json': '{"lockfileVersion":3,"bumped":true}\n' })
    const changedScript = studioFixture({ ...base, 'scripts/manifest-assets.mjs': 'export const isValidBuildId = () => true\n' })
    const changedTsconfig = studioFixture({ ...base, 'tsconfig.json': '{"compilerOptions":{"baseUrl":"./src"}}\n' })
    const changedPublisher = studioFixture({ ...base, 'scripts/publish-build.mjs': 'export const publish = () => 1\n' })

    expect(computeStudioSourceHash(bumpedLock)).not.toBe(baseHash)
    expect(computeStudioSourceHash(changedScript)).not.toBe(baseHash)
    expect(computeStudioSourceHash(changedTsconfig)).not.toBe(baseHash)
    expect(computeStudioSourceHash(changedPublisher)).not.toBe(baseHash)
  })

  it('stays byte-compatible with the PHP mirror (shared golden parity pin)', () => {
    const parity = JSON.parse(
      fs.readFileSync(path.resolve(process.cwd(), '../../tests/Fixtures/studio-source-hash-parity.json'), 'utf8'),
    )
    expect(computeStudioSourceHash(studioFixture(parity.files))).toBe(parity.sha256)
  })

  it('normalizes CRLF so line endings do not change the hash', () => {
    const lf = studioFixture(base)
    const crlf = studioFixture({ ...base, 'js/src/main.ts': 'export const a = 1\r\n' })

    expect(computeStudioSourceHash(crlf)).toBe(computeStudioSourceHash(lf))
  })
})
