// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const moduleRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const coreRoot = path.resolve(moduleRoot, '../..');
const testEmail = 'nfse-e2e@example.test';
const testPassword = 'NfseE2E!123456';
const testCnpj = '11222333000181';
const testPfxPassword = 'nfse-test-password';
const testUrl = 'http://127.0.0.1:8082';

export const requiredAssets = Object.freeze([
  'public/js/auth/common.min.js',
  'public/js/common/dashboards.min.js',
  'public/js/common/documents.min.js',
  'public/js/common/items.min.js',
]);

export const fixtureFields = Object.freeze({
  pending_invoice_id: 'NFSE_E2E_PENDING_INVOICE_ID',
  item_validation_item_id: 'NFSE_E2E_VALID_ITEM_ID',
  item_legacy_id: 'NFSE_E2E_LEGACY_ITEM_ID',
  item_failing_id: 'NFSE_E2E_FAILING_ITEM_ID',
});

export const fixtureArgs = Object.freeze([
  'artisan', 'nfse:test-harness:provision', '--company-id=1',
  `--cnpj=${testCnpj}`, '--municipio=3303302', '--item-lista=0107',
  '--codigo-nacional=010701', `--password=${testPfxPassword}`,
  '--pending-invoice-fixture', '--item-validation-fixture',
  '--item-atomicity-fixture', '--json',
]);

function execute(program, args, options = {}) {
  return execFileSync(program, args, { encoding: 'utf8', stdio: 'inherit', ...options });
}

function ensureTestingEnvironment(env) {
  if (env.APP_ENV !== 'testing' || env.DB_CONNECTION !== 'sqlite') {
    throw new Error('Smoke provisioning requires APP_ENV=testing and DB_CONNECTION=sqlite.');
  }
}

/** Change explicit keys exactly once, preserving unrelated Akaunting settings. */
export function updateDotEnv(source, values) {
  const remaining = new Set(Object.keys(values));
  const lines = source.trimEnd().split(/\r?\n/).map(line => {
    const match = /^([A-Z][A-Z0-9_]*)=/.exec(line);
    if (!match || !(match[1] in values)) return line;
    const name = match[1];
    if (!remaining.has(name)) throw new Error(`Duplicate environment key: ${name}`);
    remaining.delete(name);
    return `${name}=${values[name]}`;
  });
  for (const name of remaining) lines.push(`${name}=${values[name]}`);
  return `${lines.join('\n')}\n`;
}

export function prepareEnvironment(core = coreRoot, io = fs) {
  const databasePath = path.join(core, 'database/playwright.sqlite');
  const settings = {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: databasePath,
    APP_URL: testUrl,
    MAIL_MAILER: 'array',
    MAIL_FROM_ADDRESS: testEmail,
    MAIL_FROM_NAME: '"NFS-e E2E"',
  };
  const source = io.readFileSync(path.join(core, '.env.testing'), 'utf8');
  io.writeFileSync(path.join(core, '.env'), updateDotEnv(source, settings));
  io.closeSync(io.openSync(databasePath, 'a'));
}

/** Never emit the complete harness JSON (it contains a synthetic PFX password). */
export function parseFixtureVariables(output, fields = fixtureFields) {
  let data;
  try {
    data = JSON.parse(output);
  } catch {
    throw new Error('Fiscal harness output is not valid JSON.');
  }
  if (!data || Array.isArray(data) || typeof data !== 'object') {
    throw new Error('Fiscal harness output must be a JSON object.');
  }
  const variables = {};
  for (const [field, envName] of Object.entries(fields)) {
    const value = data[field];
    const id = typeof value === 'string' && /^\d+$/.test(value) ? Number(value) : value;
    if (!Number.isSafeInteger(id) || id <= 0) {
      throw new Error(`Missing or invalid fixture ID: ${field}`);
    }
    variables[envName] = String(id);
  }
  return variables;
}

export function writeGitHubEnvironment(variables, outputPath, io = fs) {
  if (!outputPath) throw new Error('GITHUB_ENV is required for smoke fixture IDs.');
  const lines = Object.entries(variables).map(([key, value]) => {
    if (!/^NFSE_E2E_[A-Z0-9_]+$/.test(key) || !/^[1-9]\d*$/.test(value)) {
      throw new Error(`Invalid smoke environment assignment: ${key}`);
    }
    return `${key}=${value}`;
  });
  io.appendFileSync(outputPath, `${lines.join('\n')}\n`, 'utf8');
}

export function provisionFixtures(core = coreRoot, env = process.env, run = execute, io = fs) {
  ensureTestingEnvironment(env);
  if (!env.GITHUB_ENV) throw new Error('GITHUB_ENV is required for smoke fixture IDs.');
  run('php', [
    'artisan', 'nfse:test-user:provision', '--company-id=1',
    `--email=${testEmail}`, `--password=${testPassword}`,
    '--role=admin', '--landing-page=dashboard', '--json',
  ], { cwd: core, env });
  const output = run('php', fixtureArgs, {
    cwd: core,
    env: { ...env, NFSE_TEST_HARNESS: '1' },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const variables = parseFixtureVariables(output);
  writeGitHubEnvironment(variables, env.GITHUB_ENV, io);
  return variables;
}

export function missingBrowserAssets(core = coreRoot, exists = fs.existsSync) {
  return requiredAssets.filter(relative => !exists(path.join(core, relative)));
}

export function prepareBrowserAssets(core = coreRoot, io = fs, run = execute) {
  if (missingBrowserAssets(core, io.existsSync).length) {
    const npmPath = io.realpathSync(run('which', ['npm'], { stdio: ['ignore', 'pipe', 'pipe'] }).trim());
    run('npx', ['--yes', 'node@20', npmPath, 'ci'], { cwd: core });
    run('npx', ['--yes', 'node@20', npmPath, 'run', 'production'], { cwd: core });
  }
  const missing = missingBrowserAssets(core, io.existsSync);
  if (missing.length) throw new Error(`Missing compiled Akaunting browser assets: ${missing.join(', ')}`);
}

export function verifyDatabase(core = coreRoot, run = execute) {
  const php = `if (!\\Illuminate\\Support\\Facades\\Schema::hasTable("users")) { throw new \\RuntimeException("users table missing from Playwright SQLite database"); } $userModel = user_model_class(); if (!$userModel::query()->where("email", "${testEmail}")->exists()) { throw new \\RuntimeException("Playwright test user missing from persistent database"); }`;
  run('php', ['artisan', 'tinker', `--execute=${php}`], { cwd: core });
}

export function exposeAssets(core = coreRoot, module = moduleRoot, io = fs) {
  const parent = path.join(core, 'public/modules');
  const link = path.join(parent, 'Nfse');
  io.mkdirSync(parent, { recursive: true });
  let old;
  try {
    old = io.lstatSync(link);
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }
  if (old && !old.isSymbolicLink()) throw new Error(`Refusing to overwrite non-symlink: ${link}`);
  if (old) io.unlinkSync(link);
  io.symlinkSync(module, link, 'dir');
  if (!io.existsSync(path.join(link, 'Resources/assets/js/adn-distribution-browser.js'))) {
    throw new Error('Module browser assets are not accessible through the public symlink.');
  }
}

export async function waitForServer(check, sleep, attempts = 40) {
  for (let attempt = 0; attempt < attempts; attempt++) {
    try {
      if (await check()) return;
    } catch {
      // A connection refusal before the server starts is expected.
    }
    if (attempt + 1 < attempts) await sleep(1000);
  }
  throw new Error(`Akaunting did not respond after ${attempts} health checks.`);
}

export async function startServer(core = coreRoot, { io = fs, launch = spawn, check = async () => {
  const result = await fetch(`${testUrl}/auth/login`, { signal: AbortSignal.timeout(1000) });
  return result.ok;
}, sleep = ms => new Promise(resolve => setTimeout(resolve, ms)),
  terminate = pid => process.kill(-pid, 'SIGTERM') } = {}) {
  const logPath = path.join(core, 'storage/logs/playwright-server.log');
  const log = io.openSync(logPath, 'a');
  let child;
  try {
    child = launch('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8082'], {
      cwd: core,
      detached: true,
      stdio: ['ignore', log, log],
      env: {
        ...process.env,
        NFSE_TEST_HARNESS: '1', NFSE_TEST_CNPJ: testCnpj,
        NFSE_TEST_PFX_PASSWORD: testPfxPassword,
        NFSE_TEST_PFX_PATH: path.join(core, 'storage/app/nfse/pfx', `${testCnpj}.pfx`),
      },
    });
  } finally {
    io.closeSync(log);
  }
  if (!child?.pid) throw new Error('Unable to start the Akaunting test server.');
  child.unref();
  io.writeFileSync(path.join(core, 'storage/logs/playwright-server.pid'), String(child.pid));
  try {
    await waitForServer(check, sleep);
  } catch (error) {
    try { terminate(child.pid); } catch { /* may have exited */ }
    throw new Error(`${error.message}\n${io.readFileSync(logPath, 'utf8').slice(-3000)}`);
  }
}

export async function cli(command, context = { core: coreRoot, module: moduleRoot, env: process.env }) {
  const { core, module, env } = context;
  const allowed = ['prepare-env', 'provision', 'assets', 'verify-db', 'expose-assets', 'start-server'];
  if (!allowed.includes(command)) throw new Error(`Unknown smoke setup command: ${command || '(none)'}`);
  ensureTestingEnvironment(env);
  switch (command) {
    case 'prepare-env': return prepareEnvironment(core);
    case 'provision': return provisionFixtures(core, env);
    case 'assets': return prepareBrowserAssets(core);
    case 'verify-db': return verifyDatabase(core);
    case 'expose-assets': return exposeAssets(core, module);
    case 'start-server': return startServer(core);
    default: throw new Error(`Unexpected smoke setup command: ${command}`);
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  cli(process.argv[2]).catch(error => {
    console.error(error.message);
    process.exitCode = 1;
  });
}
