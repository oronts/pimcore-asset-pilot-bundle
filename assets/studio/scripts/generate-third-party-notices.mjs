import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const studioDirectory = resolve(scriptDirectory, '..');
const projectDirectory = resolve(studioDirectory, '../..');
const lock = JSON.parse(readFileSync(join(studioDirectory, 'package-lock.json'), 'utf8'));
const outputPath = join(projectDirectory, 'THIRD_PARTY_NOTICES.md');
const checkOnly = process.argv.includes('--check');

const packages = [];
const licenseGroups = new Map();

for (const [packagePath, lockMetadata] of Object.entries(lock.packages)) {
  if (packagePath === '' || lockMetadata.dev === true) {
    continue;
  }

  const installedDirectory = join(studioDirectory, packagePath);
  const packageMetadata = readPackageMetadata(installedDirectory);
  const name = packageMetadata.name ?? packageNameFromPath(packagePath);
  const version = lockMetadata.version ?? packageMetadata.version ?? 'unknown';
  const licenseFiles = findLicenseFiles(installedDirectory);
  const licenseTexts = licenseFiles.map((filename) => ({
    filename,
    text: readFileSync(join(installedDirectory, filename), 'utf8').trim(),
  }));
  const license = lockMetadata.license
    ?? packageMetadata.license
    ?? inferLicense(licenseTexts.map(({ text }) => text).join('\n'));

  if (license === undefined) {
    throw new Error(`No license metadata or distributed license text found for ${name}@${version}.`);
  }

  const identifier = `${name}@${version}`;
  packages.push({
    identifier,
    license: normalizeLicense(license),
    source: normalizeSource(packageMetadata.repository, packageMetadata.homepage, lockMetadata.resolved),
    hasDistributedText: licenseTexts.length > 0,
  });

  for (const { filename, text } of licenseTexts) {
    if (text === '') {
      continue;
    }

    const hash = createHash('sha256').update(text).digest('hex');
    const group = licenseGroups.get(hash) ?? { filenames: new Set(), packages: [], text };
    group.filenames.add(filename);
    group.packages.push(identifier);
    licenseGroups.set(hash, group);
  }
}

packages.sort((left, right) => left.identifier.localeCompare(right.identifier));
const groups = [...licenseGroups.values()].sort((left, right) => left.packages[0].localeCompare(right.packages[0]));
const output = render(packages, groups);

if (checkOnly) {
  if (!existsSync(outputPath) || readFileSync(outputPath, 'utf8') !== output) {
    throw new Error('THIRD_PARTY_NOTICES.md is missing or does not match the frontend lockfile.');
  }

  process.stdout.write(`Verified notices for ${packages.length} frontend dependencies.\n`);
} else {
  writeFileSync(outputPath, output);
  process.stdout.write(`Wrote notices for ${packages.length} frontend dependencies.\n`);
}

function readPackageMetadata(directory) {
  const metadataPath = join(directory, 'package.json');

  return existsSync(metadataPath) ? JSON.parse(readFileSync(metadataPath, 'utf8')) : {};
}

function packageNameFromPath(packagePath) {
  return packagePath.split('node_modules/').at(-1);
}

function findLicenseFiles(directory) {
  if (!existsSync(directory)) {
    return [];
  }

  return readdirSync(directory)
    .filter((filename) => /^(licen[cs]e|notice|copying)(\..*)?$/i.test(filename))
    .sort();
}

function inferLicense(text) {
  if (/MIT License/i.test(text)) {
    return 'MIT';
  }

  if (/Apache License[\s\S]*Version 2\.0/i.test(text)) {
    return 'Apache-2.0';
  }

  return undefined;
}

function normalizeLicense(license) {
  if (typeof license === 'string') {
    return license;
  }

  if (Array.isArray(license)) {
    return license.map(normalizeLicense).join(' OR ');
  }

  return license.type ?? JSON.stringify(license);
}

function normalizeSource(repository, homepage, resolved) {
  const repositoryUrl = typeof repository === 'string' ? repository : repository?.url;
  const source = repositoryUrl ?? homepage ?? resolved ?? '';
  const normalized = source
    .replace(/^git\+/, '')
    .replace(/^git:\/\//, 'https://')
    .replace(/^git@github\.com:/, 'https://github.com/')
    .replace(/^github:/, 'https://github.com/')
    .replace(/\.git(#.*)?$/, '$1');

  return /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(normalized)
    ? `https://github.com/${normalized}`
    : normalized;
}

function escapeTableCell(value) {
  return value.replaceAll('|', '\\|').replaceAll('\n', ' ');
}

function render(inventory, groups) {
  const lines = [
    '# Third-party notices',
    '',
    'This file is generated from `assets/studio/package-lock.json` by `npm run notices:write`.',
    'It inventories every non-development package in the locked graph used to produce the prebuilt',
    'Studio remote and preserves every license or notice file distributed in installed package',
    'artifacts. Packages whose registry artifact has no standalone license file remain listed with',
    'their declared license and source. Do not edit this file manually.',
    '',
    'PHP dependencies are installed by Composer into the consuming application. Release artifacts',
    'include their locked license inventory separately.',
    '',
    '## Inventory',
    '',
    '| Package | Declared license | Source | Distributed text |',
    '| --- | --- | --- | --- |',
  ];

  for (const dependency of inventory) {
    const source = dependency.source === ''
      ? 'Not declared'
      : `[source](${dependency.source})`;
    lines.push(`| \`${escapeTableCell(dependency.identifier)}\` | ${escapeTableCell(dependency.license)} | ${source} | ${dependency.hasDistributedText ? 'Included below' : 'Not present in package artifact'} |`);
  }

  lines.push('', '## Distributed license and notice texts', '');

  for (const group of groups) {
    lines.push(
      `### ${group.packages.join(', ')}`,
      '',
      `Files: ${[...group.filenames].join(', ')}`,
      '',
      '```text',
      group.text.replaceAll('```', '``\\`'),
      '```',
      '',
    );
  }

  return `${lines.join('\n')}\n`;
}
