import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { randomUUID } from 'node:crypto'
import { fileURLToPath } from 'node:url'
import packageJson from '../package.json' with { type: 'json' }
import { computeStudioSourceHash } from './source-hash.mjs'

const currentDir = path.dirname(fileURLToPath(import.meta.url))
const packageRoot = path.resolve(currentDir, '..')
const buildRoot = path.resolve(process.env.ASSET_PILOT_OUTPUT_ROOT ?? path.join(packageRoot, '..', '..', 'public', 'studio', 'build'))
const buildId = `${packageJson.version}-${randomUUID()}`
const stagingRoot = path.join(buildRoot, '.staging')
const stagingPath = path.join(stagingRoot, buildId)
const finalPath = path.join(buildRoot, buildId)
const activePath = path.join(buildRoot, 'active.json')
fs.mkdirSync(buildRoot, { recursive: true })
const releasePublicationLock = await acquirePublicationLock(buildRoot)

try {
  const previousBuildId = fs.existsSync(activePath)
    ? JSON.parse(fs.readFileSync(activePath, 'utf8')).buildId
    : null

  fs.mkdirSync(stagingRoot, { recursive: true })

  const result = spawnSync(
    process.execPath,
    [path.join(packageRoot, 'node_modules', '@rsbuild', 'core', 'bin', 'rsbuild.js'), 'build'],
    {
      cwd: packageRoot,
      env: {
        ...process.env,
        ASSET_PILOT_BUILD_ID: buildId,
        ASSET_PILOT_BUILD_PATH: stagingPath,
        NODE_ENV: 'production',
      },
      stdio: 'inherit',
    },
  )

  if (result.status !== 0) {
    fs.rmSync(stagingPath, { recursive: true, force: true })
    throw new Error(`Studio build failed with exit code ${result.status ?? 1}`)
  }

  for (const requiredFile of ['entrypoints.json', 'exposeRemote.js', 'mf-manifest.json']) {
    const requiredPath = path.join(stagingPath, requiredFile)
    if (!fs.existsSync(requiredPath) || fs.statSync(requiredPath).size === 0) {
      fs.rmSync(stagingPath, { recursive: true, force: true })
      throw new Error(`Build validation failed: ${requiredFile} is missing`)
    }
  }

  fs.renameSync(stagingPath, finalPath)
  const sourceHash = computeStudioSourceHash(packageRoot)
  const pointerTemporaryPath = path.join(buildRoot, `.active-${randomUUID()}.json`)
  fs.writeFileSync(pointerTemporaryPath, `${JSON.stringify({ buildId, sourceHash }, null, 2)}\n`, { flag: 'wx' })
  fs.renameSync(pointerTemporaryPath, activePath)

  for (const entry of fs.readdirSync(buildRoot, { withFileTypes: true })) {
    if (!entry.isDirectory() || entry.name === '.staging' || entry.name === buildId || entry.name === previousBuildId) {
      continue
    }
    fs.rmSync(path.join(buildRoot, entry.name), { recursive: true, force: true })
  }

  process.stdout.write(`Published Studio build ${buildId}\n`)
} finally {
  releasePublicationLock()
}

async function acquirePublicationLock(root) {
  const lockPath = path.join(root, '.publish.lock')
  const token = randomUUID()
  const deadline = Date.now() + 120_000

  while (true) {
    try {
      const descriptor = fs.openSync(lockPath, 'wx')
      fs.writeFileSync(descriptor, `${JSON.stringify({ token, createdAt: new Date().toISOString() })}\n`)

      return () => {
        fs.closeSync(descriptor)
        try {
          const owner = JSON.parse(fs.readFileSync(lockPath, 'utf8'))
          if (owner.token === token) {
            fs.unlinkSync(lockPath)
          }
        } catch (error) {
          if (error.code !== 'ENOENT') {
            throw error
          }
        }
      }
    } catch (error) {
      if (error.code !== 'EEXIST') {
        throw error
      }

      let age
      try {
        age = Date.now() - fs.statSync(lockPath).mtimeMs
      } catch (statError) {
        if (statError.code === 'ENOENT') {
          continue
        }
        throw statError
      }
      if (age > 3_600_000) {
        fs.unlinkSync(lockPath)
        continue
      }
      if (Date.now() >= deadline) {
        throw new Error('Timed out waiting for another Studio build publication to finish')
      }
      await new Promise((resolve) => setTimeout(resolve, 250))
    }
  }
}
