import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import { prepareReleaseBuild } from './prepare-release-build.mjs'

const temporaryDirectories = []

afterEach(() => {
  for (const directory of temporaryDirectories.splice(0)) {
    fs.rmSync(directory, { recursive: true, force: true })
  }
})

describe('release Studio publication', () => {
  it('keeps only the active immutable generation', () => {
    const root = temporaryBuildRoot('2.0.0-active', ['2.0.0-active', '2.0.0-previous'])

    expect(prepareReleaseBuild(root)).toBe('2.0.0-active')
    expect(fs.existsSync(path.join(root, '2.0.0-active'))).toBe(true)
    expect(fs.existsSync(path.join(root, '2.0.0-previous'))).toBe(false)
    expect(fs.existsSync(path.join(root, '.staging'))).toBe(true)
  })

  it('rejects a pointer to a missing active generation', () => {
    const root = temporaryBuildRoot('2.0.0-missing', ['2.0.0-previous'])

    expect(() => prepareReleaseBuild(root)).toThrow('Missing active build directory')
    expect(fs.existsSync(path.join(root, '2.0.0-previous'))).toBe(true)
  })

  it.each(['.', '..', '../escape'])('rejects unsafe active build identifier %s', (buildId) => {
    const root = temporaryBuildRoot(buildId, [])

    expect(() => prepareReleaseBuild(root)).toThrow('active build pointer is invalid')
  })
})

function temporaryBuildRoot(buildId, generations) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'asset-pilot-release-build-'))
  temporaryDirectories.push(root)
  fs.writeFileSync(path.join(root, 'active.json'), `${JSON.stringify({ buildId })}\n`)
  fs.mkdirSync(path.join(root, '.staging'))
  for (const generation of generations) {
    fs.mkdirSync(path.join(root, generation))
  }

  return root
}
