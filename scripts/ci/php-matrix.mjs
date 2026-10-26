// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

export function testedPhpVersions(manifest) {
  const declared = manifest?.extra?.ci?.php_versions;
  const constraint = manifest?.require?.php;
  const minimum = /^\^(\d+)\.(\d+)$/.exec(constraint ?? '');
  if (!minimum || !Array.isArray(declared) || !declared.length ||
      declared.some(v => typeof v !== 'string' || !/^\d+\.\d+$/.test(v)) ||
      new Set(declared).size !== declared.length) {
    throw new Error('Define a unique tested PHP minor list and a supported ^major.minor constraint in composer.json.');
  }
  const allowed = declared.every(v => {
    const [major, minor] = v.split('.').map(Number);
    return major === Number(minimum[1]) && minor >= Number(minimum[2]);
  });
  if (!allowed || declared.some((v, i) => {
    if (!i) return false;
    const [major, minor] = v.split('.').map(Number);
    const [previousMajor, previousMinor] = declared[i - 1].split('.').map(Number);
    return major < previousMajor || (major === previousMajor && minor <= previousMinor);
  })) {
    throw new Error('Tested PHP versions must be supported and sorted in increasing order.');
  }
  return declared;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const project = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
  const manifest = JSON.parse(fs.readFileSync(path.join(project, 'composer.json'), 'utf8'));
  process.stdout.write(JSON.stringify(testedPhpVersions(manifest)) + '\n');
}
