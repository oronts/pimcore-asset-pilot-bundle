import fs from 'node:fs'
import path from 'node:path'

const assetExtensions = new Set(['js', 'css'])

export function isValidBuildId(buildId) {
  return typeof buildId === 'string'
    && /^[A-Za-z0-9._-]+$/.test(buildId)
    && buildId !== '.'
    && buildId !== '..'
}

export function verifyManifestAssets(buildPath, buildId, entrypoints, federationManifest) {
  if (!isValidBuildId(buildId)) {
    throw new Error('The Studio build identifier is invalid')
  }
  const references = [
    ...entrypointAssetReferences(entrypoints),
    ...federationManifestAssetReferences(federationManifest),
  ]

  if (references.length === 0) {
    throw new Error('The Studio manifests do not reference any JavaScript or CSS assets')
  }

  const verified = new Set()
  for (const reference of references) {
    const relativePath = normalizeAssetReference(reference.value, buildId, reference.type)
    if (verified.has(relativePath)) continue

    const artifactPath = path.resolve(buildPath, ...relativePath.split('/'))
    const buildPrefix = `${path.resolve(buildPath)}${path.sep}`
    if (!artifactPath.startsWith(buildPrefix)) {
      throw new Error(`Manifest asset escapes the active build: ${reference.value}`)
    }

    let stats
    try {
      stats = fs.lstatSync(artifactPath)
    } catch (error) {
      if (error.code === 'ENOENT') {
        throw new Error(`Missing manifest asset: ${relativePath}`)
      }
      throw error
    }
    if (!stats.isFile() || stats.size === 0) {
      throw new Error(`Manifest asset is not a non-empty regular file: ${relativePath}`)
    }

    verified.add(relativePath)
  }

  return [...verified].sort()
}

export function normalizeAssetReference(reference, buildId, expectedType) {
  if (!isValidBuildId(buildId)) {
    throw new Error('The Studio build identifier is invalid')
  }
  if (typeof reference !== 'string' || reference.length === 0 || reference !== reference.trim()) {
    throw new Error('Manifest assets must be non-empty strings without surrounding whitespace')
  }
  if (reference.includes('\\') || reference.includes('?') || reference.includes('#')) {
    throw new Error(`Manifest asset is not a normalized local path: ${reference}`)
  }
  if (/^[A-Za-z][A-Za-z0-9+.-]*:/.test(reference) || reference.startsWith('//')) {
    throw new Error(`External manifest asset is forbidden: ${reference}`)
  }

  const publicPrefix = `/bundles/orontsassetpilot/studio/build/${buildId}/`
  let relativePath = reference
  if (reference.startsWith(publicPrefix)) {
    relativePath = reference.slice(publicPrefix.length)
  } else if (reference.startsWith('/')) {
    throw new Error(`Absolute manifest asset is forbidden: ${reference}`)
  }

  const segments = relativePath.split('/')
  if (segments.some((segment) => segment === '' || segment === '.' || segment === '..' || unsafeDecodedSegment(segment))) {
    throw new Error(`Manifest asset traversal is forbidden: ${reference}`)
  }
  if (path.posix.normalize(relativePath) !== relativePath) {
    throw new Error(`Manifest asset is not normalized: ${reference}`)
  }

  const extension = path.posix.extname(relativePath).slice(1).toLowerCase()
  if (!assetExtensions.has(extension) || (expectedType !== undefined && extension !== expectedType)) {
    throw new Error(`Manifest asset has an invalid type: ${reference}`)
  }

  return relativePath
}

function entrypointAssetReferences(document) {
  const entrypoints = document?.entrypoints
  if (typeof entrypoints !== 'object' || entrypoints === null || Array.isArray(entrypoints)) {
    throw new Error('The Studio entrypoints document is invalid')
  }

  const references = []
  for (const [name, entrypoint] of Object.entries(entrypoints)) {
    if (typeof entrypoint !== 'object' || entrypoint === null || Array.isArray(entrypoint)) {
      throw new Error(`Studio entrypoint ${name} is invalid`)
    }
    for (const type of assetExtensions) {
      if (!(type in entrypoint)) continue
      if (!Array.isArray(entrypoint[type])) {
        throw new Error(`Studio entrypoint ${name}.${type} must be an array`)
      }
      for (const value of entrypoint[type]) {
        if (typeof value !== 'string') {
          throw new Error(`Studio entrypoint ${name}.${type} contains a non-string asset`)
        }
        references.push({ value, type })
      }
    }
  }

  return references
}

function federationManifestAssetReferences(manifest) {
  const remoteEntry = manifest?.metaData?.remoteEntry?.name
  if (typeof remoteEntry !== 'string') {
    throw new Error('The federation manifest remote entry is invalid')
  }

  const references = [{ value: remoteEntry, type: 'js' }]
  collectTypedAssetGroups(manifest, references)

  return references
}

function collectTypedAssetGroups(value, references) {
  if (typeof value !== 'object' || value === null) return

  for (const [key, child] of Object.entries(value)) {
    if (assetExtensions.has(key)) {
      collectAssetLeaves(child, key, references)
    } else {
      collectTypedAssetGroups(child, references)
    }
  }
}

function collectAssetLeaves(value, type, references) {
  if (typeof value === 'string') {
    references.push({ value, type })
    return
  }
  if (typeof value !== 'object' || value === null) {
    throw new Error(`Federation manifest ${type} assets contain an invalid value`)
  }

  for (const child of Object.values(value)) {
    collectAssetLeaves(child, type, references)
  }
}

function unsafeDecodedSegment(segment) {
  let decoded
  try {
    decoded = decodeURIComponent(segment)
  } catch {
    return true
  }

  return decoded === '.' || decoded === '..' || decoded.includes('/') || decoded.includes('\\')
}
