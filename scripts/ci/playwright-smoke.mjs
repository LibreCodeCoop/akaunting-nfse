// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { execFileSync, spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
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

const commonFixtureArgs = Object.freeze([
  'artisan', 'nfse:test-harness:provision', '--company-id=1',
  `--cnpj=${testCnpj}`, '--municipio=3303302', '--item-lista=0107',
  '--codigo-nacional=010701', `--password=${testPfxPassword}`,
]);

export const fixtureArgs = Object.freeze([
  ...commonFixtureArgs, '--pending-invoice-fixture', '--item-validation-fixture',
  '--item-atomicity-fixture', '--json',
]);

export const fullUiFixtureArgs = Object.freeze([
  ...commonFixtureArgs, '--substitution-fixture', '--adn-review-fixture',
  '--item-validation-fixture', '--grouped-invoice-fixture',
  '--pending-invoice-fixture', '--bulk-emission-fixture', '--json',
]);

export const fullUiFixtureFields = Object.freeze({
  substitution_invoice_id: 'NFSE_E2E_SUBSTITUTION_INVOICE_ID',
  adn_review_document_id: 'NFSE_E2E_ADN_REVIEW_DOCUMENT_ID',
  adn_vendor_id: 'NFSE_E2E_ADN_VENDOR_ID',
  adn_category_id: 'NFSE_E2E_ADN_CATEGORY_ID',
  adn_item_id: 'NFSE_E2E_ADN_ITEM_ID',
  item_validation_item_id: 'NFSE_E2E_ITEM_VALIDATION_ID',
  grouped_invoice_id: 'NFSE_E2E_GROUPED_INVOICE_ID',
  pending_invoice_id: 'NFSE_E2E_PENDING_INVOICE_ID',
  bulk_ready_invoice_id: 'NFSE_E2E_BULK_READY_INVOICE_ID',
  bulk_blocked_invoice_id: 'NFSE_E2E_BULK_BLOCKED_INVOICE_ID',
});

export const bulkUiFixtureArgs = Object.freeze([
  ...commonFixtureArgs, '--bulk-emission-fixture', '--json',
]);

export const bulkUiFixtureFields = Object.freeze({
  bulk_ready_invoice_id: 'NFSE_E2E_BULK_READY_INVOICE_ID',
  bulk_blocked_invoice_id: 'NFSE_E2E_BULK_BLOCKED_INVOICE_ID',
});

const fixtureProfiles = Object.freeze({
  smoke: { args: fixtureArgs, fields: fixtureFields },
  'full-ui': { args: fullUiFixtureArgs, fields: fullUiFixtureFields },
  'bulk-ui': { args: bulkUiFixtureArgs, fields: bulkUiFixtureFields },
});

/** Bound child execution independently of the broader GitHub job budget. */
export function execute(program, args, options = {}, invoke = execFileSync) {
  const timeout = program === 'npx' ? 360_000 : program === 'php' ? 120_000 : 15_000;
  return invoke(program, args, {
    encoding: 'utf8', stdio: 'inherit', timeout, killSignal: 'SIGTERM', ...options,
  });
}

function processFailure(label, error) {
  const stderr = error?.stderr?.toString?.('utf8') || error?.message || 'Unknown process failure';
  const safe = stderr.replaceAll(testPassword, '[REDACTED]').replaceAll(testPfxPassword, '[REDACTED]');
  return new Error(`${label} failed: ${safe.slice(-2000)}`);
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

export function provisionFixtures(core = coreRoot, env = process.env, run = execute, io = fs, profile = 'smoke') {
  const configuration = fixtureProfiles[profile];
  if (!configuration) throw new Error(`Unknown fiscal fixture profile: ${profile}`);
  ensureTestingEnvironment(env);
  if (!env.GITHUB_ENV) throw new Error('GITHUB_ENV is required for fiscal fixture IDs.');
  try {
    run('php', [
      'artisan', 'nfse:test-user:provision', '--company-id=1',
      `--email=${testEmail}`, `--password=${testPassword}`,
      '--role=admin', '--landing-page=dashboard', '--json',
    ], { cwd: core, env, stdio: ['ignore', 'pipe', 'pipe'] });
  } catch (error) {
    throw processFailure('Akaunting test user provisioning', error);
  }
  let output;
  try {
    output = run('php', configuration.args, {
      cwd: core,
      env: { ...env, NFSE_TEST_HARNESS: '1' },
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch (error) {
    throw processFailure('Synthetic fiscal fixture provisioning', error);
  }
  const variables = parseFixtureVariables(output, configuration.fields);
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
  const asset = 'Resources/assets/js/adn-distribution-browser.js';
  // Validate the replacement before touching a previously working symlink.
  if (!io.existsSync(path.join(module, asset))) {
    throw new Error('Module browser assets are not accessible through the requested target.');
  }
  io.mkdirSync(parent, { recursive: true });
  let old;
  try {
    old = io.lstatSync(link);
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }
  if (old && !old.isSymbolicLink()) throw new Error(`Refusing to overwrite non-symlink: ${link}`);
  if (old && io.readlinkSync(link) === module) return;
  // Stage a verified symlink and atomically replace the old one only on success.
  const stagedLink = path.join(parent, `.Nfse-staged-${randomUUID()}`);
  io.symlinkSync(module, stagedLink, 'dir');
  try {
    if (!io.existsSync(path.join(stagedLink, asset))) {
      throw new Error('Module browser assets are not accessible through the staged symlink.');
    }
    io.renameSync(stagedLink, link);
  } catch (error) {
    try { io.unlinkSync(stagedLink); } catch (cleanupError) {
      if (cleanupError.code !== 'ENOENT') {
        throw new AggregateError([error, cleanupError], 'Failed to restore module asset symlink staging.');
      }
    }
    throw error;
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
  const pidPath = path.join(core, 'storage/logs/playwright-server.pid');
  try {
    io.writeFileSync(pidPath, String(child.pid));
    await waitForServer(check, sleep);
  } catch (error) {
    try { terminate(child.pid); } catch { /* may have exited */ }
    try { io.unlinkSync(pidPath); } catch (cleanupError) {
      if (cleanupError.code !== 'ENOENT') throw cleanupError;
    }
    const tail = io.readFileSync(logPath, 'utf8').slice(-3000);
    throw new Error(`${error.message}\n${tail.replaceAll(testPassword, '[REDACTED]').replaceAll(testPfxPassword, '[REDACTED]')}`);
  }
}

/** Stop only the detached test server recorded by this disposable harness. */
export function stopServer(core = coreRoot, {
  io = fs, terminate = pid => process.kill(-pid, 'SIGTERM'),
} = {}) {
  const pidPath = path.join(core, 'storage/logs/playwright-server.pid');
  let raw;
  try {
    raw = io.readFileSync(pidPath, 'utf8');
  } catch (error) {
    if (error.code === 'ENOENT') return false;
    throw error;
  }
  if (!/^[1-9]\d*$/.test(raw.trim()) || !Number.isSafeInteger(Number(raw.trim()))) {
    throw new Error('Invalid Akaunting test server PID.');
  }
  try {
    terminate(Number(raw.trim()));
  } catch (error) {
    if (error.code !== 'ESRCH') throw error;
  }
  io.unlinkSync(pidPath);
  return true;
}

/** Aggregate RSS without recording process arguments, paths or credentials. */
export function summarizeSmokeProcessRss(output) {
  const rss = { chromium: 0, php: 0, node: 0 };
  for (const line of output.split(/\r?\n/)) {
    const match = /^\s*(\S+)\s+(\d+)\s*$/.exec(line);
    if (!match) continue;
    const command = match[1];
    const group = /^(?:chrome|chromium)/.test(command) ? 'chromium'
      : /^php(?:\d|$)/.test(command) ? 'php'
        : command === 'node' ? 'node' : null;
    if (group) rss[group] += Number(match[2]);
  }
  return rss;
}

export function sampleSmokeProcessRss(run = execFileSync) {
  const output = run('ps', ['-eo', 'comm=,rss='], {
    encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
  });
  return summarizeSmokeProcessRss(String(output));
}

/** Run the unchanged Smoke suite while retaining browser-exit and RSS evidence. */
export async function runBrowserSmoke({
  env = process.env, launch = spawn, sampleRss = sampleSmokeProcessRss,
  print = console.log, intervalMs = 10_000,
} = {}) {
  ensureTestingEnvironment(env);
  const started = Date.now();
  const peaks = { chromium: 0, php: 0, node: 0 };
  let samples = 0;
  let sampleWarning = false;
  const child = launch('npx', ['playwright', 'test', '--project=smoke'], {
    cwd: moduleRoot, stdio: 'inherit',
    env: { ...env, DEBUG: [env.DEBUG, 'pw:browser'].filter(Boolean).join(',') },
  });
  const sample = () => {
    try {
      const rss = sampleRss();
      samples++;
      for (const name of Object.keys(peaks)) {
        peaks[name] = Math.max(peaks[name], rss[name] ?? 0);
      }
      print('Smoke RSS MiB (Chromium/PHP/Node): ' + ['chromium', 'php', 'node']
        .map(name => Math.round((rss[name] ?? 0) / 1024)).join('/'));
    } catch {
      if (!sampleWarning) {
        sampleWarning = true;
        print('Smoke RSS sampling unavailable; browser exit diagnostics remain enabled.');
      }
    }
  };
  sample();
  const interval = setInterval(sample, intervalMs);
  interval.unref?.();
  try {
    const result = await new Promise((resolve, reject) => {
      child.once('error', reject);
      child.once('close', (code, signal) => resolve({ code, signal }));
    });
    if (result.signal) throw new Error('Playwright Smoke ended with signal ' + result.signal);
    if (result.code !== 0) throw new Error('Playwright Smoke exited with code ' + result.code);
  } finally {
    clearInterval(interval);
    print('Smoke duration ' + (Date.now() - started) +
      'ms, RSS peaks MiB (Chromium/PHP/Node): ' + ['chromium', 'php', 'node']
        .map(name => Math.round(peaks[name] / 1024)).join('/') + '; samples: ' + samples);
  }
}

export async function cli(command, context = { core: coreRoot, module: moduleRoot, env: process.env }) {
  const { core, module, env } = context;
  const allowed = ['prepare-env', 'provision', 'provision-full-ui', 'provision-bulk-ui', 'assets', 'verify-db', 'expose-assets', 'start-server', 'stop-server', 'run-smoke'];
  if (!allowed.includes(command)) throw new Error(`Unknown smoke setup command: ${command || '(none)'}`);
  ensureTestingEnvironment(env);
  switch (command) {
    case 'prepare-env': return prepareEnvironment(core);
    case 'provision': return provisionFixtures(core, env);
    case 'provision-full-ui': return provisionFixtures(core, env, execute, fs, 'full-ui');
    case 'provision-bulk-ui': return provisionFixtures(core, env, execute, fs, 'bulk-ui');
    case 'assets': return prepareBrowserAssets(core);
    case 'verify-db': return verifyDatabase(core);
    case 'expose-assets': return exposeAssets(core, module);
    case 'start-server': return startServer(core);
    case 'stop-server': return stopServer(core);
    case 'run-smoke': return runBrowserSmoke({ env });
    default: throw new Error(`Unexpected smoke setup command: ${command}`);
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const command = process.argv[2] || '(none)';
  const started = Date.now();
  cli(process.argv[2]).then(() => {
    console.log(`CI setup ${command} succeeded in ${Date.now() - started}ms`);
  }).catch(error => {
    const message = error.message.replaceAll(testPassword, '[REDACTED]').replaceAll(testPfxPassword, '[REDACTED]');
    console.error(`CI setup ${command} failed after ${Date.now() - started}ms: ${message}`);
    process.exitCode = 1;
  });
}
