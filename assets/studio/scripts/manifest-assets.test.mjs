import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import { isValidBuildId, normalizeAssetReference, verifyManifestAssets } from './manifest-assets.mjs'

const temporaryDirectories = []

afterEach(() => {
  for (const directory of temporaryDirectories.splice(0)) {
    fs.rmSync(directory, { recursive: true, force: true })
  }
})

describe('Studio manifest asset verification', () => {
  it.each(['.', '..', '', 'build/path', String.raw`build\\path`])('rejects invalid build identifier %s', (buildId) => {
    expect(isValidBuildId(buildId)).toBe(false)
    expect(() => normalizeAssetReference('exposeRemote.js', buildId, 'js')).toThrow('build identifier is invalid')
  })

  it('verifies every entrypoint and federation asset against the active build', () => {
    const buildId = '2.0.0-test'
    const buildPath = temporaryBuild(['exposeRemote.js', 'static/js/main.js', 'static/css/main.css', 'static/js/remoteEntry.js'])

    expect(verifyManifestAssets(
      buildPath,
      buildId,
      entrypoints(buildId, ['static/js/main.js'], ['static/css/main.css']),
      manifest(['static/js/main.js'], ['static/css/main.css']),
    )).toEqual(['exposeRemote.js', 'static/css/main.css', 'static/js/main.js', 'static/js/remoteEntry.js'])
  })

  it.each([
    'https://example.test/2.0.0-test/exposeRemote.js',
    '//example.test/remote.js',
    '/tmp/remote.js',
    '../remote.js',
    'static/js/../../remote.js',
    'static/js/%2e%2e/remote.js',
  ])('rejects unsafe asset reference %s', (reference) => {
    expect(() => normalizeAssetReference(reference, '2.0.0-test', 'js')).toThrow()
  })

  it('rejects a manifest asset missing from the active build', () => {
    const buildId = '2.0.0-test'
    const buildPath = temporaryBuild(['exposeRemote.js', 'static/js/remoteEntry.js'])

    expect(() => verifyManifestAssets(
      buildPath,
      buildId,
      entrypoints(buildId, ['static/js/missing.js']),
      manifest(),
    )).toThrow('Missing manifest asset: static/js/missing.js')
  })
})

function temporaryBuild(files) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'asset-pilot-manifest-'))
  temporaryDirectories.push(directory)
  for (const file of files) {
    const filePath = path.join(directory, file)
    fs.mkdirSync(path.dirname(filePath), { recursive: true })
    fs.writeFileSync(filePath, 'artifact')
  }

  return directory
}

function entrypoints(buildId, js = [], css = []) {
  return {
    entrypoints: {
      exposeRemote: {
        js: [`/bundles/orontsassetpilot/studio/build/${buildId}/exposeRemote.js`],
        css: [],
      },
      main: { js, css },
    },
  }
}

function manifest(js = [], css = []) {
  return {
    metaData: { remoteEntry: { name: 'static/js/remoteEntry.js' } },
    exposes: [{ assets: { js: { sync: js, async: [] }, css: { sync: css, async: [] } } }],
  }
}
