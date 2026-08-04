#!/usr/bin/env node
// Release npm-audit gate with a documented, expiry-bound allowlist for advisories deferred to the
// coordinated Pimcore Studio 2026.1 host upgrade (see ../../SECURITY.md "Known upstream advisories").
// It fails the build on ANY advisory at low severity or above that is not explicitly allowlisted, so a
// new vulnerability still breaks CI while the two deferred, transitive-only react-router records pass.
import { execSync } from 'node:child_process'

// All deferred advisories are transitive build-tooling or Studio-UI dependencies whose fix ships only in
// the breaking Pimcore Studio 2026.1 line; none is bundled into the shipped browser remote (react-router
// is a Module Federation shared singleton the host provides at runtime; undici is a Node HTTP client used
// only by the Module Federation build plugin). Each entry names its package and why it is not reachable.
const ALLOWLIST = new Set([
  // react-router / react-router-dom, transitive through @pimcore/studio-ui-bundle (Studio 2025.4);
  // shared singleton provided by the host, not shipped in this remote.
  'GHSA-wrjc-x8rr-h8h6',
  'GHSA-337j-9hxr-rhxg',
  'GHSA-jjmj-jmhj-qwj2',
  // undici, transitive through @module-federation/node (build plugin); build-time only, not shipped.
  'GHSA-8xcm-r25x-g524',
  'GHSA-4cwx-7wf7-3272',
  'GHSA-m8rv-5g2x-5cg5',
  'GHSA-jr45-8vmc-qm54',
  'GHSA-v3r7-h72x-cjcm',
])
const REVIEW_BY = '2026-11-01'

let report
try {
  report = execSync('npm audit --audit-level=low --json', { encoding: 'utf8' })
} catch (error) {
  // npm audit exits non-zero when advisories exist; its JSON report is still emitted on stdout.
  report = error.stdout || ''
}

let audit
try {
  audit = JSON.parse(report)
} catch {
  console.error('audit-allowlist: could not parse `npm audit --json` output')
  process.exit(2)
}

const found = new Set()
for (const entry of Object.values(audit.vulnerabilities || {})) {
  for (const via of entry.via || []) {
    if (via && typeof via === 'object' && typeof via.url === 'string') {
      const match = via.url.match(/GHSA-[0-9a-z-]+/i)
      if (match) {
        found.add(match[0])
      }
    }
  }
}

const disallowed = [...found].filter((id) => !ALLOWLIST.has(id))
if (disallowed.length > 0) {
  console.error(`audit-allowlist: FAIL — advisories not in the allowlist: ${disallowed.join(', ')}`)
  process.exit(1)
}

if (new Date() > new Date(REVIEW_BY)) {
  console.error(`audit-allowlist: FAIL — allowlist review date ${REVIEW_BY} has passed; re-evaluate the deferred advisories.`)
  process.exit(1)
}

console.log(`audit-allowlist: PASS — only allowlisted advisories present (${[...found].join(', ') || 'none'}); review by ${REVIEW_BY}.`)
