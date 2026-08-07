import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import packageJson from '../package.json' with { type: 'json' }
import { findChunkBudgetViolations } from './chunk-budget.mjs'
import { isValidBuildId, verifyManifestAssets } from './manifest-assets.mjs'
import { computeStudioSourceHash } from './source-hash.mjs'

const currentDir = path.dirname(fileURLToPath(import.meta.url))
const studioDir = path.resolve(currentDir, '..')
const buildRoot = path.resolve(process.env.ASSET_PILOT_OUTPUT_ROOT ?? path.join(currentDir, '..', '..', '..', 'public', 'studio', 'build'))
const activePath = path.join(buildRoot, 'active.json')

if (!fs.existsSync(activePath)) {
  throw new Error(`Missing active build pointer: ${activePath}`)
}

const { buildId, sourceHash } = JSON.parse(fs.readFileSync(activePath, 'utf8'))
if (!isValidBuildId(buildId)) {
  throw new Error('The active build pointer is invalid')
}

// Reject a stale remote: internal coherence is not enough, the artifact must have been built from the
// current source. A missing hash means a pre-freshness build, which is treated as stale.
const currentSourceHash = computeStudioSourceHash(studioDir)
if (typeof sourceHash !== 'string' || sourceHash.length === 0) {
  throw new Error('The active build pointer has no source hash; rebuild the Studio remote (npm run build)')
}
if (sourceHash !== currentSourceHash) {
  throw new Error('The shipped Studio remote is stale: it was not built from the current source. Run npm run build.')
}

const buildPath = path.join(buildRoot, buildId)
const entrypointsPath = path.join(buildPath, 'entrypoints.json')
const remotePath = path.join(buildPath, 'exposeRemote.js')
const federationManifestPath = path.join(buildPath, 'mf-manifest.json')

for (const requiredPath of [entrypointsPath, remotePath, federationManifestPath]) {
  if (!fs.existsSync(requiredPath) || fs.statSync(requiredPath).size === 0) {
    throw new Error(`Missing build artifact: ${requiredPath}`)
  }
}

const entrypointsDocument = JSON.parse(fs.readFileSync(entrypointsPath, 'utf8'))
const remoteEntries = entrypointsDocument?.entrypoints?.exposeRemote?.js
if (!Array.isArray(remoteEntries) || remoteEntries.length !== 1 || !remoteEntries[0].endsWith(`/${buildId}/exposeRemote.js`)) {
  throw new Error('The active entrypoints do not expose the expected remote')
}

const manifest = JSON.parse(fs.readFileSync(federationManifestPath, 'utf8'))
if (manifest?.metaData?.buildInfo?.buildVersion !== process.env.npm_package_version) {
  throw new Error('The frontend artifact version does not match package.json')
}

verifyManifestAssets(buildPath, buildId, entrypointsDocument, manifest)

const chunkViolations = findChunkBudgetViolations(buildPath, packageJson.assetPilotBuild)
if (chunkViolations.length > 0) {
  throw new Error(`Studio chunk budget exceeded:\n${chunkViolations.join('\n')}`)
}

process.stdout.write(`Verified Studio build ${buildId}\n`)
