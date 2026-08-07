import { defineConfig } from '@rsbuild/core'
import { pluginReact } from '@rsbuild/plugin-react'
import { pluginModuleFederation } from '@module-federation/rsbuild-plugin';
import { pluginGenerateEntrypoints } from '@pimcore/studio-ui-bundle/rsbuild/plugins';
import path from 'path'
import { fileURLToPath } from 'url'
import packages from './package.json'
import { isValidBuildId } from './scripts/manifest-assets.mjs'

const currentDir = path.dirname(fileURLToPath(import.meta.url))
const buildId = process.env.ASSET_PILOT_BUILD_ID ?? 'development'
if (!isValidBuildId(buildId)) {
  throw new Error('ASSET_PILOT_BUILD_ID contains unsupported characters')
}
const buildPath = process.env.ASSET_PILOT_BUILD_PATH
  ? path.resolve(process.env.ASSET_PILOT_BUILD_PATH)
  : path.resolve(currentDir, '..', '..', 'public', 'studio', 'build', buildId)
const assetBase = (process.env.ASSET_PILOT_ASSET_BASE ?? '/bundles/orontsassetpilot/studio/build').replace(/\/$/, '')
const assetPrefix = `${assetBase}/${buildId}`

const nodeEnv = process.env.NODE_ENV;
let env: 'development' | 'production' = 'production';

const isDevServer = nodeEnv === 'dev-server';
if (nodeEnv !== env) {
  env = 'development';
}

export default defineConfig({
  mode: env,
  // Disable the persistent build cache: a warm cache can skip re-emitting the module-federation
  // entrypoints (exposeRemote.js), which silently breaks module registration. Reliability over a
  // few seconds of rebuild time for a deploy-time bundle build.
  performance: {
    buildCache: false,
  },
  server: {
    host: process.env.ASSET_PILOT_DEV_HOST ?? 'localhost',
    port: Number(process.env.ASSET_PILOT_DEV_PORT ?? 3040),
    cors: {
      origin: process.env.ASSET_PILOT_DEV_ORIGIN ?? 'http://localhost:3000',
      credentials: true,
    },
  },
  dev: {
    ...(!isDevServer ? {assetPrefix} : {}),
    client: {
      host: process.env.ASSET_PILOT_DEV_HOST ?? 'localhost',
      port: Number(process.env.ASSET_PILOT_DEV_PORT ?? 3040),
      protocol: process.env.ASSET_PILOT_DEV_PROTOCOL ?? 'ws'
    }
  },
  source: {
    entry: {
      main: './js/src/main.ts'
    }
  },
  output: {
    manifest: true,
    cleanDistPath: false,
    assetPrefix,
    distPath: {
      root: buildPath
    },
  },
  tools: {
    bundlerChain: (chain) => {
      chain.output.uniqueName('oronts_asset_pilot_bundle');
    },
  },
  plugins: [
    pluginGenerateEntrypoints(),
    pluginReact(),
    pluginModuleFederation({
      name: 'oronts_asset_pilot_bundle',
      filename: 'static/js/remoteEntry.js',
      exposes: {
        '.': './js/src/plugins.ts',
      },
      dts: false,
      remotes: {
        '@pimcore/studio-ui-bundle': `promise new Promise((resolve, reject) => {
          const studioUIBundleRemoteUrl = window.StudioUIBundleRemoteUrl
          if (typeof studioUIBundleRemoteUrl !== 'string' || studioUIBundleRemoteUrl.length === 0) {
            throw new Error('Studio UI remote URL is unavailable')
          }

          const isAlreadyInitializedError = (error) => {
            return typeof error === 'object'
              && error !== null
              && typeof error.message === 'string'
              && error.message.toLowerCase().includes('already been initialized')
          }

          const resolveContainer = () => {
            const container = window['pimcore_studio_ui_bundle']
            if (!container || typeof container.get !== 'function' || typeof container.init !== 'function') {
              throw new Error('Studio UI remote container did not initialize')
            }
            resolve({
              get: (request) => container.get(request),
              init: (...args) => {
                try {
                  const result = container.init(...args)
                  if (result && typeof result.catch === 'function') {
                    return result.catch((error) => {
                      if (isAlreadyInitializedError(error)) return undefined
                      throw error
                    })
                  }
                  return result
                } catch (error) {
                  if (isAlreadyInitializedError(error)) return undefined
                  throw error
                }
              }
            })
          }

          const absoluteRemoteUrl = new URL(studioUIBundleRemoteUrl, document.baseURI).href
          const existing = Array.from(document.scripts).find((script) => script.src === absoluteRemoteUrl)
          if (existing && window['pimcore_studio_ui_bundle']) {
            try {
              resolveContainer()
            } catch (error) {
              reject(error)
            }
            return
          }

          const script = existing ?? document.createElement('script')
          const timeout = window.setTimeout(() => {
            reject(new Error('Studio UI remote loading timed out'))
          }, 15000)
          script.addEventListener('load', () => {
            window.clearTimeout(timeout)
            try {
              resolveContainer()
            } catch (error) {
              reject(error)
            }
          }, { once: true })
          script.addEventListener('error', () => {
            window.clearTimeout(timeout)
            reject(new Error('Studio UI remote failed to load'))
          }, { once: true })
          if (!existing) {
            script.src = absoluteRemoteUrl
            document.head.appendChild(script)
          }
        })
        `,
      },
      shared: {
        ...packages.dependencies,
        react: {
          singleton: true,
          eager: true,
          requiredVersion: false,
        },
        'react-dom': {
          singleton: true,
          eager: true,
          requiredVersion: false,
        },
        'i18next': {
          singleton: true,
          eager: true,
          requiredVersion: false,
        },
        'react-i18next': {
          singleton: true,
          eager: true,
          requiredVersion: false,
        },
      },
    })
  ]
})
