import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@pimcore/studio-ui-bundle/api', () => ({
  getPrefix: () => '/pimcore-studio/api',
}))

import { ApiError, assetPilotApi, mergeConflictRecovery } from './api'
import type { MergeResult, OperationRun, OperationRunListResponse } from '../types'

const previewResult: MergeResult = {
  checksum: 'abc123',
  canonicalId: 7,
  dryRun: true,
  planToken: 'signed-plan',
  runId: null,
  status: null,
  statusUrl: null,
  dispositions: [],
}

const operationRun: OperationRun = {
  id: '0123456789abcdef0123456789abcdef',
  kind: 'organize',
  status: 'running',
  totalCount: 2,
  processedCount: 1,
  succeededCount: 1,
  skippedCount: 0,
  blockedCount: 0,
  failedCount: 0,
  attempt: 1,
  retryOf: null,
  request: { objectId: 42 },
  error: null,
  createdAt: '2026-07-15T08:00:00+00:00',
  startedAt: '2026-07-15T08:00:01+00:00',
  updatedAt: '2026-07-15T08:00:02+00:00',
  completedAt: null,
  items: [],
}

describe('assetPilotApi.mergeDuplicates', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('sends no plan token during preview', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => previewResult,
    })
    vi.stubGlobal('fetch', fetchMock)
    const signal = new AbortController().signal

    await assetPilotApi.mergeDuplicates('abc123', 7, 'quarantine', true, undefined, signal)

    expect(fetchMock).toHaveBeenCalledWith(
      '/pimcore-studio/api/asset-pilot/duplicates/merge',
      expect.objectContaining({
        method: 'POST',
        credentials: 'same-origin',
        signal,
        body: JSON.stringify({
          checksum: 'abc123',
          canonicalId: 7,
          strategy: 'quarantine',
          dryRun: true,
        }),
      }),
    )
  })

  it('sends the reviewed plan token when applying', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ ...previewResult, dryRun: false, planToken: null }),
    })
    vi.stubGlobal('fetch', fetchMock)

    await assetPilotApi.mergeDuplicates('abc123', 7, 'quarantine', false, 'signed-plan')

    const init = fetchMock.mock.calls[0][1] as RequestInit
    expect(JSON.parse(String(init.body))).toEqual({
      checksum: 'abc123',
      canonicalId: 7,
      strategy: 'quarantine',
      dryRun: false,
      planToken: 'signed-plan',
    })
  })

  it('resumes a persisted merge run without resubmitting its consumed plan', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ ...previewResult, dryRun: false, planToken: null, runId: 'a'.repeat(32), status: 'running' }),
    })
    vi.stubGlobal('fetch', fetchMock)
    const signal = new AbortController().signal

    await assetPilotApi.resumeDuplicateMerge('a'.repeat(32), signal)

    expect(fetchMock).toHaveBeenCalledWith(
      '/pimcore-studio/api/asset-pilot/duplicates/merge',
      expect.objectContaining({
        method: 'POST',
        signal,
        body: JSON.stringify({ runId: 'a'.repeat(32) }),
      }),
    )
  })
})

describe('ApiError structured details', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('preserves the full error body so recovery fields survive a failed request', async () => {
    const runId = 'c'.repeat(32)
    const body = { error: 'The duplicate merge run could not be finalized; retry to complete it.', runId, rootRunId: runId, statusUrl: `operations/runs/${runId}` }
    const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 409, json: async () => body })
    vi.stubGlobal('fetch', fetchMock)

    const error = await assetPilotApi.mergeDuplicates('abc123', 7, undefined, false, 'signed-plan').catch(e => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(409)
    expect((error as ApiError).message).toBe(body.error)
    expect((error as ApiError).details).toEqual(body)
  })

  it('extracts a recoverable merge conflict only when a runId is present', () => {
    const runId = 'd'.repeat(32)
    expect(mergeConflictRecovery({ error: 'x', runId, statusUrl: 'operations/runs/x' })).toEqual({ runId, rootRunId: undefined, statusUrl: 'operations/runs/x' })
    expect(mergeConflictRecovery({ error: 'stale plan' })).toBeNull()
    expect(mergeConflictRecovery({ error: 'x', runId: '' })).toBeNull()
    expect(mergeConflictRecovery(null)).toBeNull()
    expect(mergeConflictRecovery('nope')).toBeNull()
  })
})

describe('assetPilotApi operation runs', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('loads the current user operation history with cancellation support', async () => {
    const response: OperationRunListResponse = { items: [operationRun], limit: 20 }
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => response })
    vi.stubGlobal('fetch', fetchMock)
    const signal = new AbortController().signal

    await expect(assetPilotApi.getOperationRuns(20, signal)).resolves.toEqual(response)

    expect(fetchMock).toHaveBeenCalledWith(
      '/pimcore-studio/api/asset-pilot/operations/runs?limit=20',
      expect.objectContaining({ credentials: 'same-origin', signal }),
    )
  })

  it('loads a run with cancellation support', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => operationRun })
    vi.stubGlobal('fetch', fetchMock)
    const signal = new AbortController().signal

    await expect(assetPilotApi.getOperationRun('run/id', signal)).resolves.toEqual(operationRun)

    expect(fetchMock).toHaveBeenCalledWith(
      '/pimcore-studio/api/asset-pilot/operations/runs/run%2Fid',
      expect.objectContaining({ credentials: 'same-origin', signal }),
    )
  })

  it('requests cancellation for a run', async () => {
    const response = { runId: operationRun.id, status: 'cancel_requested' }
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => response })
    vi.stubGlobal('fetch', fetchMock)

    await expect(assetPilotApi.cancelOperationRun(operationRun.id)).resolves.toEqual(response)

    expect(fetchMock).toHaveBeenCalledWith(
      `/pimcore-studio/api/asset-pilot/operations/runs/${operationRun.id}/cancel`,
      expect.objectContaining({ method: 'POST', credentials: 'same-origin' }),
    )
  })

  it('queues a retry for a run', async () => {
    const response = {
      runId: 'fedcba9876543210fedcba9876543210',
      retryOf: operationRun.id,
      status: 'queued',
      statusUrl: 'operations/runs/fedcba9876543210fedcba9876543210',
    }
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => response })
    vi.stubGlobal('fetch', fetchMock)

    await expect(assetPilotApi.retryOperationRun(operationRun.id)).resolves.toEqual(response)

    expect(fetchMock).toHaveBeenCalledWith(
      `/pimcore-studio/api/asset-pilot/operations/runs/${operationRun.id}/retry`,
      expect.objectContaining({ method: 'POST', credentials: 'same-origin' }),
    )
  })
})

describe('assetPilotApi operation recovery', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('previews and applies the same review limit with its signed token', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) })
    vi.stubGlobal('fetch', fetchMock)
    const signal = new AbortController().signal

    await assetPilotApi.previewOperationRecovery(25, signal)
    await assetPilotApi.applyOperationRecovery(25, 'recovery-plan', signal)

    expect(fetchMock.mock.calls[0][0]).toBe('/pimcore-studio/api/asset-pilot/operations/recovery')
    expect(fetchMock.mock.calls[0][1]).toEqual(expect.objectContaining({ method: 'POST', signal }))
    expect(fetchMock.mock.calls[1][1]).toEqual(expect.objectContaining({ method: 'POST', signal }))
    expect(fetchMock.mock.calls.map(call => JSON.parse(String((call[1] as RequestInit).body)))).toEqual([
      { limit: 25, apply: false },
      { limit: 25, apply: true, planToken: 'recovery-plan' },
    ])
  })
})

describe('assetPilotApi dead delivery retry', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('previews and applies the exact limit with its signed delivery token', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) })
    vi.stubGlobal('fetch', fetchMock)
    const signal = new AbortController().signal

    await assetPilotApi.previewDeliveryRetry(50, signal)
    await assetPilotApi.applyDeliveryRetry(50, 'delivery-plan', signal)

    expect(fetchMock.mock.calls[0][0]).toBe('/pimcore-studio/api/asset-pilot/operations/deliveries/retry')
    expect(fetchMock.mock.calls[0][1]).toEqual(expect.objectContaining({ method: 'POST', signal }))
    expect(fetchMock.mock.calls[1][1]).toEqual(expect.objectContaining({ method: 'POST', signal }))
    expect(fetchMock.mock.calls.map(call => JSON.parse(String((call[1] as RequestInit).body)))).toEqual([
      { limit: 50, apply: false },
      { limit: 50, apply: true, planToken: 'delivery-plan' },
    ])
  })
})

describe('assetPilotApi empty-folder plans', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('previews and applies the exact folder selection with its reviewed token', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) })
    vi.stubGlobal('fetch', fetchMock)

    await assetPilotApi.previewDeleteEmptyFolders([7, 8])
    await assetPilotApi.applyDeleteEmptyFolders([7, 8], 'folder-plan')

    expect(fetchMock.mock.calls.map(call => JSON.parse(String((call[1] as RequestInit).body)))).toEqual([
      { ids: [7, 8], dryRun: true },
      { ids: [7, 8], dryRun: false, planToken: 'folder-plan' },
    ])
  })
})

describe('assetPilotApi rule preview plans', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('returns the plan token issued with a rule preview', async () => {
    const response = { operations: [], planToken: 'actor-bound-preview-token' }
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => response })
    vi.stubGlobal('fetch', fetchMock)
    const signal = new AbortController().signal

    await expect(assetPilotApi.previewRule('product/assets', 42, signal)).resolves.toEqual(response)

    expect(fetchMock).toHaveBeenCalledWith(
      '/pimcore-studio/api/asset-pilot/rules/product%2Fassets/preview?objectId=42',
      expect.objectContaining({ credentials: 'same-origin', signal }),
    )
  })

  it('submits the reviewed plan token when applying a rule', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ results: [] }) })
    vi.stubGlobal('fetch', fetchMock)

    await assetPilotApi.applyRule('product-assets', 42, 'actor-bound-preview-token')

    expect(fetchMock).toHaveBeenCalledWith(
      '/pimcore-studio/api/asset-pilot/rules/product-assets/apply',
      expect.objectContaining({
        method: 'POST',
        credentials: 'same-origin',
        body: JSON.stringify({ objectId: 42, planToken: 'actor-bound-preview-token' }),
      }),
    )
  })
})

describe('assetPilotApi organize plans', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('sends the signed single-object preview token only when applying', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) })
    vi.stubGlobal('fetch', fetchMock)

    await assetPilotApi.organize({ objectId: 42, dryRun: true, async: false })
    await assetPilotApi.organize({ objectId: 42, dryRun: false, async: false, planToken: 'single-plan' })

    expect(JSON.parse(String((fetchMock.mock.calls[0][1] as RequestInit).body))).toEqual({ objectId: 42, dryRun: true, async: false })
    expect(JSON.parse(String((fetchMock.mock.calls[1][1] as RequestInit).body))).toEqual({ objectId: 42, dryRun: false, async: false, planToken: 'single-plan' })
  })

  it('keeps candidate browsing separate from signed bulk preview and apply', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) })
    vi.stubGlobal('fetch', fetchMock)

    await assetPilotApi.organizeBulkPreview('Product', 2, 25)
    await assetPilotApi.organizeBulk({ className: 'Product', dryRun: true, async: true, batchSize: 50 })
    await assetPilotApi.organizeBulk({ className: 'Product', dryRun: false, async: true, batchSize: 50, planToken: 'bulk-plan' })

    expect(fetchMock.mock.calls[0][0]).toBe('/pimcore-studio/api/asset-pilot/operations/bulk-preview')
    expect(JSON.parse(String((fetchMock.mock.calls[0][1] as RequestInit).body))).toEqual({ className: 'Product', page: 2, limit: 25 })
    expect(fetchMock.mock.calls[1][0]).toBe('/pimcore-studio/api/asset-pilot/organize/bulk')
    expect(JSON.parse(String((fetchMock.mock.calls[1][1] as RequestInit).body))).toEqual({ className: 'Product', dryRun: true, async: true, batchSize: 50 })
    expect(JSON.parse(String((fetchMock.mock.calls[2][1] as RequestInit).body))).toEqual({ className: 'Product', dryRun: false, async: true, batchSize: 50, planToken: 'bulk-plan' })
  })
})

describe('assetPilotApi unused mutation plans', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('binds delete, move, and quarantine applies to their reviewed inputs and tokens', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) })
    vi.stubGlobal('fetch', fetchMock)

    await assetPilotApi.previewBulkDeleteAssets([7, 8])
    await assetPilotApi.applyBulkDeleteAssets([7, 8], 'delete-plan')
    await assetPilotApi.previewBulkMoveAssets([7, 8], '/archive')
    await assetPilotApi.applyBulkMoveAssets([7, 8], '/archive', 'move-plan')
    await assetPilotApi.previewBulkQuarantineAssets([7, 8])
    await assetPilotApi.applyBulkQuarantineAssets([7, 8], 'quarantine-plan')

    const bodies = fetchMock.mock.calls.map(call => JSON.parse(String((call[1] as RequestInit).body)))
    expect(bodies).toEqual([
      { assetIds: [7, 8], dryRun: true },
      { assetIds: [7, 8], dryRun: false, planToken: 'delete-plan' },
      { assetIds: [7, 8], targetFolder: '/archive', dryRun: true },
      { assetIds: [7, 8], targetFolder: '/archive', dryRun: false, planToken: 'move-plan' },
      { assetIds: [7, 8], dryRun: true },
      { assetIds: [7, 8], dryRun: false, planToken: 'quarantine-plan' },
    ])
  })
})

describe('assetPilotApi metadata mutation plans', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('binds tag and property applies to exact reviewed inputs and tokens', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) })
    vi.stubGlobal('fetch', fetchMock)

    await assetPilotApi.previewBulkTagAssets([7, 8], [2, 3], true)
    await assetPilotApi.applyBulkTagAssets([7, 8], [2, 3], true, 'tag-plan')
    await assetPilotApi.previewBulkSetProperty([7, 8], 'source', 'text', 'catalog')
    await assetPilotApi.applyBulkSetProperty([7, 8], 'source', 'text', 'catalog', 'property-plan')

    const bodies = fetchMock.mock.calls.map(call => JSON.parse(String((call[1] as RequestInit).body)))
    expect(bodies).toEqual([
      { assetIds: [7, 8], tagIds: [2, 3], replace: true, dryRun: true },
      { assetIds: [7, 8], tagIds: [2, 3], replace: true, dryRun: false, planToken: 'tag-plan' },
      { assetIds: [7, 8], name: 'source', type: 'text', data: 'catalog', dryRun: true },
      { assetIds: [7, 8], name: 'source', type: 'text', data: 'catalog', dryRun: false, planToken: 'property-plan' },
    ])
  })
})


describe('assetPilotApi integrity heal plans', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('binds apply to the exact reviewed IDs and token', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) })
    vi.stubGlobal('fetch', fetchMock)

    await assetPilotApi.previewHealAssets([7, 8])
    await assetPilotApi.applyHealAssets([7, 8], 'heal-plan')

    expect(fetchMock.mock.calls.map(call => JSON.parse(String((call[1] as RequestInit).body)))).toEqual([
      { ids: [7, 8], dryRun: true },
      { ids: [7, 8], dryRun: false, planToken: 'heal-plan' },
    ])
  })
})
