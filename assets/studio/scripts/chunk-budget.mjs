import fs from 'node:fs'
import path from 'node:path'
import { gzipSync } from 'node:zlib'

export function findChunkBudgetViolations(buildPath, budgets) {
  return collectJavaScriptFiles(buildPath).flatMap((filePath) => {
    const source = fs.readFileSync(filePath)
    const rawBytes = source.byteLength
    const gzipBytes = gzipSync(source).byteLength
    const relativePath = path.relative(buildPath, filePath)
    const violations = []

    if (rawBytes > budgets.maxJavaScriptChunkBytes) {
      violations.push(`${relativePath}: ${rawBytes} raw bytes exceeds ${budgets.maxJavaScriptChunkBytes}`)
    }
    if (gzipBytes > budgets.maxJavaScriptChunkGzipBytes) {
      violations.push(`${relativePath}: ${gzipBytes} gzip bytes exceeds ${budgets.maxJavaScriptChunkGzipBytes}`)
    }

    return violations
  })
}

function collectJavaScriptFiles(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const entryPath = path.join(directory, entry.name)
    if (entry.isDirectory()) return collectJavaScriptFiles(entryPath)

    return entry.isFile() && entry.name.endsWith('.js') ? [entryPath] : []
  })
}
