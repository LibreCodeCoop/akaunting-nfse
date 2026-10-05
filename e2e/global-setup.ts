// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { execFileSync } from 'node:child_process';
import { existsSync, realpathSync } from 'node:fs';
import path from 'node:path';

const REQUIRED_CORE_ASSETS = [
  'public/js/auth/common.min.js',
  'public/js/common/dashboards.min.js',
  'public/js/common/documents.min.js',
];

function isLocalDeterministicTarget(baseURL: string): boolean {
  try {
    const url = new URL(baseURL);

    return ['127.0.0.1', 'localhost', '::1'].includes(url.hostname);
  } catch {
    return false;
  }
}

function missingCoreAssets(coreRoot: string): string[] {
  return REQUIRED_CORE_ASSETS.filter((asset) => !existsSync(path.join(coreRoot, asset)));
}

function npmExecutable(): string {
  const resolved = execFileSync('which', ['npm'], { encoding: 'utf8' }).trim();

  if (resolved === '') {
    throw new Error('npm executable was not found while preparing Akaunting browser assets.');
  }

  return realpathSync(resolved);
}

function runNpmWithNode20(coreRoot: string, args: string[]): void {
  execFileSync(
    'npx',
    ['--yes', 'node@20', npmExecutable(), ...args],
    {
      cwd: coreRoot,
      env: {
        ...process.env,
        npm_config_engine_strict: 'false',
      },
      stdio: 'inherit',
    },
  );
}

export default async function globalSetup(): Promise<void> {
  const baseURL = process.env.NFSE_E2E_BASE_URL ?? 'http://localhost:8082';

  if (!isLocalDeterministicTarget(baseURL)) {
    return;
  }

  const coreRoot = path.resolve(process.cwd(), '..', '..');
  const packageJson = path.join(coreRoot, 'package.json');
  const packageLock = path.join(coreRoot, 'package-lock.json');

  if (!existsSync(packageJson) || !existsSync(packageLock)) {
    return;
  }

  const missingBeforeBuild = missingCoreAssets(coreRoot);

  if (missingBeforeBuild.length === 0) {
    return;
  }

  console.log(
    'Akaunting core browser assets are missing; building them deterministically with Node 20:',
    missingBeforeBuild.join(', '),
  );

  runNpmWithNode20(coreRoot, ['ci']);
  runNpmWithNode20(coreRoot, ['run', 'production']);

  const missingAfterBuild = missingCoreAssets(coreRoot);

  if (missingAfterBuild.length > 0) {
    throw new Error(
      'Akaunting frontend build completed without required browser assets: '
        + missingAfterBuild.join(', '),
    );
  }
}
