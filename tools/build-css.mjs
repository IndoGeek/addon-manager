#!/usr/bin/env node
// build-css.mjs — generate root.css from the modular source files in components/styles/.

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const stylesDir = join(repoRoot, 'components', 'styles');
const indexCss = join(stylesDir, 'index.css');
const outFile = join(repoRoot, 'root.css');

const GUARD = `/* GENERATED FILE — DO NOT EDIT. root.css is generated from the modular sources in components/styles/; edit those and regenerate with: node tools/build-css.mjs (build.sh runs this automatically before every deploy). */
`;

// Parse @import './x.css' lines out of index.css, preserving order.
const imports = readFileSync(indexCss, 'utf8')
  .split('\n')
  .map((line) => line.trim())
  .filter((line) => line.startsWith('@import'))
  .map((line) => {
    const match = line.match(/^@import\s+'([^']+)'\s*;$/);

    if (!match) {
      throw new Error(`Unparseable @import in index.css: ${line}`);
    }

    return match[1];
  });

if (imports.length === 0) {
  throw new Error('No @import lines found in components/styles/index.css');
}

const chunks = imports.map((relativePath) => {
  const filePath = join(stylesDir, relativePath);
  const content = readFileSync(filePath, 'utf8').trimEnd();

  return `/* ==== ${relativePath} ==== */\n\n${content}\n`;
});

writeFileSync(outFile, `${GUARD}\n${chunks.join('\n')}`, 'utf8');

console.log(`build-css: wrote ${outFile} from ${imports.length} source files`);
