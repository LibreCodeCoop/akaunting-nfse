// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {
  cli, exposeAssets, fixtureArgs, missingBrowserAssets, parseFixtureVariables,
  prepareBrowserAssets, prepareEnvironment, provisionFixtures, requiredAssets,
  updateDotEnv, verifyDatabase, waitForServer, writeGitHubEnvironment, startServer,
} from '../scripts/ci/playwright-smoke.mjs';

const ids = {
  pending_invoice_id: 1,
  item_validation_item_id: 2,
  item_legacy_id: 3,
  item_failing_id: 4,
};

function tmpDir(t) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nfse-smoke-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  return dir;
}

test('dotenv update replaces exact keys, preserves unrelated keys and does not duplicate values', () => {
  const source = 'APP_URL=http://old.local\nDB_CONNECTION=mysql\nOTHER_DB_CONNECTION=keep\n';
  const updated = updateDotEnv(source, { APP_URL: 'http://127.0.0.1:8082', DB_CONNECTION: 'sqlite', NEW_SETTING: 'set' });
  assert.equal(updated, 'APP_URL=http://127.0.0.1:8082\nDB_CONNECTION=sqlite\nOTHER_DB_CONNECTION=keep\nNEW_SETTING=set\n');
  assert.throws(() => updateDotEnv('APP_URL=first\nAPP_URL=second\n', { APP_URL: 'final' }), /Duplicate environment key/);
});

test('environment preparation only changes the disposable Akaunting config', t => {
  const dir = tmpDir(t);
  fs.mkdirSync(path.join(dir, 'database'));
  fs.writeFileSync(path.join(dir, '.env.testing'), 'APP_URL=http://old.test\nDB_CONNECTION=mysql\nCUSTOM_SETTING=still-here\n');
  prepareEnvironment(dir);
  const env = fs.readFileSync(path.join(dir, '.env'), 'utf8');
  assert.match(env, /APP_URL=http:\/\/127\.0\.0\.1:8082/);
  assert.match(env, /DB_CONNECTION=sqlite/);
  assert.match(env, /DB_DATABASE=.*playwright\.sqlite/);
  assert.match(env, /MAIL_FROM_NAME="NFS-e E2E"/);
  assert.match(env, /CUSTOM_SETTING=still-here/);
  assert.ok(fs.existsSync(path.join(dir, 'database/playwright.sqlite')));
});

test('fixture JSON maps only vetted numeric IDs; ignores certificates, paths and secrets', () => {
  const env = parseFixtureVariables(JSON.stringify({ ...ids, pfx_path: '/example/secret.pfx', pfx_password: 'not-for-logs' }));
  assert.deepEqual(env, {
    NFSE_E2E_PENDING_INVOICE_ID: '1', NFSE_E2E_VALID_ITEM_ID: '2',
    NFSE_E2E_LEGACY_ITEM_ID: '3', NFSE_E2E_FAILING_ITEM_ID: '4',
  });
  for (const bad of [0, -3, 1.2, '1\nHACKED=value', 'NaN', Number.MAX_SAFE_INTEGER + 1, null]) {
    assert.throws(() => parseFixtureVariables(JSON.stringify({ ...ids, item_failing_id: bad })), /invalid fixture ID/);
  }
  assert.throws(() => parseFixtureVariables('{missing json'), /not valid JSON/);
  assert.throws(() => parseFixtureVariables('[]'), /JSON object/);
  assert.throws(() => parseFixtureVariables(JSON.stringify({ pending_invoice_id: 1 })), /invalid fixture ID/);
});

test('fixture output is appended atomically to GITHUB_ENV and refuses unsafe assignments', t => {
  const directory = tmpDir(t);
  const envFile = path.join(directory, 'github-env');
  writeGitHubEnvironment(parseFixtureVariables(JSON.stringify(ids)), envFile);
  const result = fs.readFileSync(envFile, 'utf8');
  assert.equal(result.split('\n').filter(Boolean).length, 4);
  assert.match(result, /NFSE_E2E_FAILING_ITEM_ID=4/);
  assert.doesNotMatch(result, /PFX|password|SECRET/);
  assert.throws(() => writeGitHubEnvironment({ NFSE_E2E_FAILING_ITEM_ID: '4\nHI=bad' }, envFile), /Invalid/);
  assert.throws(() => writeGitHubEnvironment({ NFSE_E2E_FAILING_ITEM_ID: '4' }, ''), /GITHUB_ENV/);
});

test('fixture provisioning invokes Akaunting and propagates errors before exporting IDs', t => {
  const directory = tmpDir(t);
  const envFile = path.join(directory, 'GITHUB_ENV');
  const calls = [];
  const runner = (program, args, options) => {
    calls.push({ program, args, options });
    return args.includes('nfse:test-harness:provision') ? JSON.stringify(ids) : '';
  };
  const env = { APP_ENV: 'testing', DB_CONNECTION: 'sqlite', GITHUB_ENV: envFile };
  assert.deepEqual(provisionFixtures(directory, env, runner), parseFixtureVariables(JSON.stringify(ids)));
  assert.equal(calls.length, 2);
  assert.equal(calls[0].program, 'php');
  assert.ok(calls[0].args.includes('nfse:test-user:provision'));
  assert.deepEqual(calls[1].args, fixtureArgs);
  assert.equal(calls[1].options.env.NFSE_TEST_HARNESS, '1');
  assert.match(fs.readFileSync(envFile, 'utf8'), /NFSE_E2E_PENDING_INVOICE_ID=1/);
  assert.throws(() => provisionFixtures(directory, { ...env, APP_ENV: 'production' }, runner), /requires APP_ENV=testing/);
  assert.equal(calls.length, 2);
  assert.throws(() => provisionFixtures(directory, { ...env, GITHUB_ENV: '' }, runner), /GITHUB_ENV/);
  assert.equal(calls.length, 2);
  fs.writeFileSync(envFile, '');
  assert.throws(() => provisionFixtures(directory, env, () => '{not json'), /not valid JSON/);
  assert.equal(fs.readFileSync(envFile, 'utf8'), '');
});

test('assets are built under Node 20 only when needed and always checked afterward', () => {
  const core = '/fake/akaunting';
  const existing = new Set(requiredAssets.map(asset => path.join(core, asset)));
  const calls = [];
  const io = { existsSync: item => existing.has(item), realpathSync: () => '/node/npm-cli.js' };
  const runner = (prog, args) => {
    calls.push([prog, ...args]);
    if (prog === 'which') return '/usr/bin/npm\n';
    if (prog === 'npx' && args.includes('production')) {
      for (const relative of requiredAssets) existing.add(path.join(core, relative));
    }
    return '';
  };
  assert.deepEqual(missingBrowserAssets(core, io.existsSync), []);
  prepareBrowserAssets(core, io, runner);
  assert.equal(calls.length, 0);
  existing.delete(path.join(core, requiredAssets[3]));
  prepareBrowserAssets(core, io, runner);
  assert.deepEqual(calls, [
    ['which', 'npm'],
    ['npx', '--yes', 'node@20', '/node/npm-cli.js', 'ci'],
    ['npx', '--yes', 'node@20', '/node/npm-cli.js', 'run', 'production'],
  ]);
  existing.clear();
  assert.throws(() => prepareBrowserAssets(core, io, () => '/usr/bin/npm\n'), /Missing compiled/);
});

test('database verification runs the existing table and user checks', () => {
  const calls = [];
  verifyDatabase('/tmp/fake-core', (program, args, options) => calls.push({ program, args, options }));
  assert.equal(calls.length, 1);
  assert.equal(calls[0].program, 'php');
  assert.equal(calls[0].args[1], 'tinker');
  assert.match(calls[0].args[2], /hasTable\("users"\)/);
  assert.match(calls[0].args[2], /nfse-e2e@example\.test/);
  assert.equal(calls[0].options.cwd, '/tmp/fake-core');
});

test('module assets are exposed via a checked symlink; unrelated paths are never overwritten', t => {
  const dir = tmpDir(t);
  const core = path.join(dir, 'core');
  const module = path.join(dir, 'Nfse');
  fs.mkdirSync(path.join(core, 'public/modules'), { recursive: true });
  fs.mkdirSync(path.join(module, 'Resources/assets/js'), { recursive: true });
  fs.writeFileSync(path.join(module, 'Resources/assets/js/adn-distribution-browser.js'), '');
  exposeAssets(core, module);
  const link = path.join(core, 'public/modules/Nfse');
  assert.equal(fs.readlinkSync(link), module);
  exposeAssets(core, module);
  assert.equal(fs.readlinkSync(link), module);
  fs.unlinkSync(link);
  fs.mkdirSync(link);
  assert.throws(() => exposeAssets(core, module), /Refusing to overwrite non-symlink/);
});

test('server health probing tolerates startup errors but fails after its bounded retry budget', async () => {
  let attempts = 0;
  const sleeps = [];
  await waitForServer(() => {
    attempts++;
    if (attempts === 1) throw new Error('Connection refused');
    return attempts === 3;
  }, ms => { sleeps.push(ms); }, 4);
  assert.equal(attempts, 3);
  assert.deepEqual(sleeps, [1000, 1000]);
  await assert.rejects(waitForServer(() => false, () => {}, 2), /after 2 health checks/);
});

test('server startup preserves fixture environment, writes PID and aborts cleanly on timeout', async t => {
  const core = tmpDir(t);
  fs.mkdirSync(path.join(core, 'storage/logs'), { recursive: true });
  let observed;
  let unreferenced = false;
  const launch = (program, args, options) => {
    observed = { program, args, options };
    return { pid: 12345, unref: () => { unreferenced = true; } };
  };
  await startServer(core, { launch, check: async () => true, sleep: () => {} });
  assert.equal(observed.program, 'php');
  assert.deepEqual(observed.args, ['artisan', 'serve', '--host=127.0.0.1', '--port=8082']);
  assert.equal(observed.options.env.NFSE_TEST_HARNESS, '1');
  assert.equal(observed.options.env.NFSE_TEST_CNPJ, '11222333000181');
  assert.match(observed.options.env.NFSE_TEST_PFX_PATH, /11222333000181\.pfx$/);
  assert.ok(unreferenced);
  assert.equal(fs.readFileSync(path.join(core, 'storage/logs/playwright-server.pid'), 'utf8'), '12345');

  const terminated = [];
  await assert.rejects(startServer(core, {
    launch, check: async () => false, sleep: () => {},
    terminate: pid => terminated.push(pid),
  }), /did not respond after 40 health checks/);
  assert.deepEqual(terminated, [12345]);
});

test('workflow invokes unit-tested setup commands instead of embedding shell parsing', () => {
  const file = path.resolve(import.meta.dirname, '../.github/workflows/playwright-smoke.yml');
  const workflow = fs.readFileSync(file, 'utf8');
  for (const command of ['prepare-env', 'provision', 'assets', 'verify-db', 'expose-assets', 'start-server']) {
    assert.ok(workflow.includes(`playwright-smoke.mjs ${command}`), `${command} must be wired in CI`);
  }
  assert.match(workflow, /node --test frontend-tests\/playwright-smoke-setup\.test\.mjs/);
  assert.doesNotMatch(workflow, /php -r|sed -i|for asset in|for field in|tinker --execute/);
});

test('unknown setup subcommands fail closed', async () => {
  await assert.rejects(cli('do-everything', { core: '/unused', module: '/unused', env: {} }), /Unknown smoke setup command/);
  await assert.rejects(cli('expose-assets', { core: '/unused', module: '/unused', env: {} }), /requires APP_ENV=testing/);
});
