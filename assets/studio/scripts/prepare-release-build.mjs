import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { isValidBuildId } from './manifest-assets.mjs'

export function prepareReleaseBuild(buildRoot) {
  const activePath = path.join(buildRoot, 'active.json')
  if (!fs.existsSync(activePath)) {
    throw new Error(`Missing active build pointer: ${activePath}`)
  }

  const { buildId } = JSON.parse(fs.readFileSync(activePath, 'utf8'))
  if (!isValidBuildId(buildId)) {
    throw new Error('The active build pointer is invalid')
  }

  const activeBuildPath = path.join(buildRoot, buildId)
  if (!fs.existsSync(activeBuildPath) || !fs.statSync(activeBuildPath).isDirectory()) {
    throw new Error(`Missing active build directory: ${activeBuildPath}`)
  }

  for (const entry of fs.readdirSync(buildRoot, { withFileTypes: true })) {
    if (entry.isDirectory() && entry.name !== '.staging' && entry.name !== buildId) {
      fs.rmSync(path.join(buildRoot, entry.name), { recursive: true, force: true })
    }
  }

  return buildId
}

const scriptPath = fileURLToPath(import.meta.url)
if (process.argv[1] !== undefined && path.resolve(process.argv[1]) === scriptPath) {
  const currentDir = path.dirname(scriptPath)
  const buildRoot = path.resolve(
    process.env.ASSET_PILOT_OUTPUT_ROOT ?? path.join(currentDir, '..', '..', '..', 'public', 'studio', 'build'),
  )
  const buildId = prepareReleaseBuild(buildRoot)
  process.stdout.write(`Prepared release Studio build ${buildId}\n`)
}
