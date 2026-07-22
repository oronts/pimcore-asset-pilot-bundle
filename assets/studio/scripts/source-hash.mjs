import crypto from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'

// The files whose content determines the built Studio remote. Editing any of these without rebuilding
// leaves the shipped remote stale; the freshness hash is what makes verify-build catch that, instead of
// only checking internal manifest/version coherence.
const SOURCE_ROOTS = ['js/src', 'rsbuild.config.ts', 'package.json']

/**
 * A deterministic SHA-256 over the Studio build inputs. Paths are normalized to forward slashes and
 * sorted, and text is normalized to LF, so the same source produces the same hash across machines and
 * checkouts regardless of directory-read order or line-ending settings.
 *
 * @param {string} studioDir absolute path to the assets/studio directory
 * @returns {string}
 */
export function computeStudioSourceHash(studioDir) {
  const files = []
  for (const root of SOURCE_ROOTS) {
    collectFiles(path.join(studioDir, root), files)
  }
  files.sort()

  const hash = crypto.createHash('sha256')
  for (const file of files) {
    const relative = path.relative(studioDir, file).split(path.sep).join('/')
    const content = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n')
    hash.update(relative)
    hash.update('\0')
    hash.update(content)
    hash.update('\0')
  }

  return hash.digest('hex')
}

function collectFiles(target, out) {
  const stats = fs.statSync(target)
  if (stats.isFile()) {
    out.push(target)
    return
  }
  for (const entry of fs.readdirSync(target, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
    if (entry.name === 'node_modules' || entry.name.startsWith('.')) {
      continue
    }
    collectFiles(path.join(target, entry.name), out)
  }
}
