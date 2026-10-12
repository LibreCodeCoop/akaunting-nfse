// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {
  cli, execute, exposeAssets, fixtureArgs, fullUiFixtureArgs, fullUiFixtureFields, bulkUiFixtureArgs, bulkUiFixtureFields, missingBrowserAssets, parseFixtureVariables,
  prepareBrowserAssets, prepareEnvironment, provisionFixtures, requiredAssets,
  updateDotEnv, verifyDatabase, waitForServer, writeGitHubEnvironment, startServer, stopServer,
  summarizeSmokeProcessRss, sampleSmokeProcessRss, runBrowserSmoke,
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

test('RSS diagnostics aggregate process names without command lines or secrets', () => {
  const sample = 'chrome-headless 4096\nchromium 1024\nphp8.3 2048\nnode 512\nother 9999\nmalformed\n';
  assert.deepEqual(summarizeSmokeProcessRss(sample), { chromium: 5120, php: 2048, node: 512 });
  assert.deepEqual(sampleSmokeProcessRss(() => sample), { chromium: 5120, php: 2048, node: 512 });
});

test('Smoke runner collects RSS, enables browser exit logs and preserves failure status', async () => {
  const output = [];
  const invocations = [];
  let code = 0;
  const launch = (program, args, options) => {
    invocations.push({ program, args, options });
    const child = new EventEmitter();
    queueMicrotask(() => child.emit('close', code, null));
    return child;
  };
  const options = {
    env: { APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DEBUG: 'other' },
    launch,
    sampleRss: () => ({ chromium: 2048, php: 1024, node: 512 }),
    print: text => output.push(text),
  };
  await runBrowserSmoke(options);
  assert.equal(invocations[0].program, 'npx');
  assert.deepEqual(invocations[0].args, ['playwright', 'test', '--project=smoke']);
  assert.equal(invocations[0].options.env.DEBUG, 'other,pw:browser');
  assert.equal(invocations[0].options.stdio, 'inherit');
  assert.match(output.join('\n'), /RSS peaks MiB .* 2\/1\/1; samples: 1/);
  code = 9;
  await assert.rejects(runBrowserSmoke(options), /exited with code 9/);
  await assert.rejects(runBrowserSmoke({ ...options, env: { APP_ENV: 'production', DB_CONNECTION: 'sqlite' } }),
    /requires APP_ENV=testing/);
});

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

test('full UI profile exports ten vetted IDs without leaking secrets', t => {
  const dir = tmpDir(t);
  const envFile = path.join(dir, 'github-env');
  const payload = Object.fromEntries(Object.keys(fullUiFixtureFields).map((key, i) => [key, i + 1]));
  payload.pfx_password = 'not-for-logs';
  const calls = [];
  const runner = (program, args, options) => {
    calls.push({args, options});
    return args.includes('nfse:test-harness:provision') ? JSON.stringify(payload) : '';
  };
  const env = { APP_ENV: 'testing', DB_CONNECTION: 'sqlite', GITHUB_ENV: envFile };
  assert.equal(Object.keys(provisionFixtures(dir, env, runner, fs, 'full-ui')).length, 10);
  assert.deepEqual(calls[1].args, fullUiFixtureArgs);
  assert.doesNotMatch(fs.readFileSync(envFile, 'utf8'), /password|pfx/i);
  fs.writeFileSync(envFile, '');
  delete payload.adn_vendor_id;
  assert.throws(() => provisionFixtures(dir, env, runner, fs, 'full-ui'), /Missing or invalid fixture ID/);
  assert.equal(fs.readFileSync(envFile, 'utf8'), '');
});

test('subprocess failure redacts credentials and never exports IDs', t => {
  const dir = tmpDir(t);
  const env = { APP_ENV: 'testing', DB_CONNECTION: 'sqlite', GITHUB_ENV: path.join(dir, 'env') };
  const error = { stderr: 'NfseE2E!123456 nfse-test-password' };
  assert.throws(() => provisionFixtures(dir, env, () => { throw error; }),
    e => e.message.includes('[REDACTED]') && !e.message.includes('NfseE2E!123456') && !e.message.includes('nfse-test-password'));
  assert.equal(fs.existsSync(env.GITHUB_ENV), false);
});

test('bulk UI profile validates only two required synthetic invoice IDs', t => {
  const dir = tmpDir(t);
  const envFile = path.join(dir, 'github-env');
  const payload = { bulk_ready_invoice_id: 77, bulk_blocked_invoice_id: 78, pfx_password: 'never-log' };
  const calls = [];
  const runner = (command, args, options) => {
    calls.push({ command, args, options });
    return args.includes('nfse:test-harness:provision') ? JSON.stringify(payload) : '';
  };
  const env = { APP_ENV: 'testing', DB_CONNECTION: 'sqlite', GITHUB_ENV: envFile };
  assert.deepEqual(provisionFixtures(dir, env, runner, fs, 'bulk-ui'), {
    NFSE_E2E_BULK_READY_INVOICE_ID: '77', NFSE_E2E_BULK_BLOCKED_INVOICE_ID: '78',
  });
  assert.deepEqual(calls[1].args, bulkUiFixtureArgs);
  assert.deepEqual(Object.keys(bulkUiFixtureFields), ['bulk_ready_invoice_id', 'bulk_blocked_invoice_id']);
  assert.doesNotMatch(fs.readFileSync(envFile, 'utf8'), /password|pfx/i);
  fs.writeFileSync(envFile, '');
  delete payload.bulk_blocked_invoice_id;
  assert.throws(() => provisionFixtures(dir, env, runner, fs, 'bulk-ui'), /Missing or invalid fixture ID/);
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

test('failed atomic symlink replacement keeps the original path and cleans staging', t => {
  const dir = tmpDir(t);
  const core = path.join(dir, 'core');
  const parent = path.join(core, 'public/modules');
  const original = path.join(dir, 'original');
  const replacement = path.join(dir, 'replacement');
  for (const module of [original, replacement]) {
    fs.mkdirSync(path.join(module, 'Resources/assets/js'), { recursive: true });
    fs.writeFileSync(path.join(module, 'Resources/assets/js/adn-distribution-browser.js'), '');
  }
  fs.mkdirSync(parent, { recursive: true });
  fs.symlinkSync(original, path.join(parent, 'Nfse'));
  const io = Object.create(fs);
  io.renameSync = () => { throw new Error('Synthetic atomic rename failure'); };
  assert.throws(() => exposeAssets(core, replacement, io), /Synthetic atomic rename failure/);
  assert.equal(fs.readlinkSync(path.join(parent, 'Nfse')), original);
  assert.deepEqual(fs.readdirSync(parent), ['Nfse']);
  exposeAssets(core, replacement);
  assert.equal(fs.readlinkSync(path.join(parent, 'Nfse')), replacement);
});

test('invalid replacement target preserves the original module symlink', t => {
  const dir = tmpDir(t);
  const core = path.join(dir, 'core');
  const original = path.join(dir, 'original');
  fs.mkdirSync(path.join(original, 'Resources/assets/js'), { recursive: true });
  fs.writeFileSync(path.join(original, 'Resources/assets/js/adn-distribution-browser.js'), '');
  const link = path.join(core, 'public/modules/Nfse');
  fs.mkdirSync(path.dirname(link), { recursive: true });
  fs.symlinkSync(original, link);
  assert.throws(() => exposeAssets(core, path.join(dir, 'missing')), /assets are not accessible/);
  assert.equal(fs.readlinkSync(link), original);
});

test('server PID write failure terminates launched child and avoids orphan processes', async t => {
  const core = tmpDir(t);
  fs.mkdirSync(path.join(core, 'storage/logs'), { recursive: true });
  const io = Object.create(fs);
  io.writeFileSync = () => { throw new Error('Synthetic storage failure'); };
  let killed = null;
  await assert.rejects(startServer(core, {
    io, launch: () => ({ pid: 54321, unref() {} }),
    check: async () => true, sleep: async () => {},
    terminate: pid => { killed = pid; },
  }), /Synthetic storage failure/);
  assert.equal(killed, 54321);
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
  assert.equal(fs.existsSync(path.join(core, 'storage/logs/playwright-server.pid')), false);
});

test('server cleanup is idempotent and rejects invalid PID files', t => {
  const dir = tmpDir(t);
  const logs = path.join(dir, 'storage/logs');
  fs.mkdirSync(logs, { recursive: true });
  const pid = path.join(logs, 'playwright-server.pid');
  let killed = 0;
  assert.equal(stopServer(dir), false);
  fs.writeFileSync(pid, '12345');
  assert.equal(stopServer(dir, { terminate: n => { assert.equal(n, 12345); killed++; } }), true);
  assert.equal(stopServer(dir), false);
  fs.writeFileSync(pid, '-2');
  assert.throws(() => stopServer(dir), /Invalid Akaunting test server PID/);
  assert.equal(killed, 1);
});

test('full UI uses shared setup, compiled-asset cache and cleanup', () => {
  const file = path.resolve(import.meta.dirname, '../.github/workflows/playwright-full-ui.yml');
  const workflow = fs.readFileSync(file, 'utf8');
  for (const command of ['prepare-env', 'provision-full-ui', 'assets', 'verify-db', 'expose-assets', 'start-server', 'stop-server']) {
    assert.ok(workflow.includes('playwright-smoke.mjs ' + command));
  }
  assert.match(workflow, /Cache compiled Akaunting browser assets/);
  assert.doesNotMatch(workflow, /FIXTURE_JSON|php -r|sed -i/);
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

test('bulk UI is scheduled for changes to shared harness and browser contracts', () => {
  const source = fs.readFileSync(path.resolve(import.meta.dirname, '../.github/workflows/playwright-bulk-ui.yml'), 'utf8');
  const pullRequest = source.match(/  pull_request:\n    paths:\n((?:      - [^\n]+\n)+)/)?.[1];
  assert.ok(pullRequest, 'bulk UI must retain a path-scoped pull_request trigger');
  const globs = [...pullRequest.matchAll(/^      - '([^']+)'$/gm)].map(match => match[1]);
  for (const changedFile of [
    'scripts/ci/playwright-smoke.mjs',
    'frontend-tests/playwright-smoke-setup.test.mjs',
    'e2e/global-setup.ts',
    'e2e/support/auth.ts',
    'playwright.config.ts',
    'package.json',
    'package-lock.json',
    'e2e/nfse-bulk-emission.spec.ts',
  ]) {
    assert.ok(globs.some(glob => path.matchesGlob(changedFile, glob)),
      `A change to ${changedFile} must trigger the deterministic Bulk UI job`);
  }
  assert.equal(globs.some(glob => path.matchesGlob('LICENSE', glob)), false);
});

test('bulk UI workflow calls the tested setup without emitting raw fixture JSON', () => {
  const file = path.resolve(import.meta.dirname, '../.github/workflows/playwright-bulk-ui.yml');
  const workflow = fs.readFileSync(file, 'utf8');
  for (const command of ['prepare-env', 'provision-bulk-ui', 'assets', 'verify-db', 'expose-assets', 'start-server', 'stop-server']) {
    assert.ok(workflow.includes('playwright-smoke.mjs ' + command));
  }
  assert.match(workflow, /Cache compiled Akaunting browser assets/);
  assert.doesNotMatch(workflow, /FIXTURE_JSON|php -r|sed -i|printf '%s\\n'/);
});

test('subprocesses have bounded timeouts and CLI failures record stage duration', () => {
  const options = [];
  for (const command of ['php', 'npx', 'which']) {
    execute(command, ['--version'], {}, (_prog, _args, opts) => { options.push(opts); return ''; });
  }
  assert.deepEqual(options.map(x => x.timeout), [120_000, 360_000, 15_000]);
  assert.ok(options.every(x => x.killSignal === 'SIGTERM'));
  assert.throws(() => execute(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], {
    timeout: 80, stdio: ['ignore', 'pipe', 'pipe'],
  }), error => error.code === 'ETIMEDOUT');
  const script = path.resolve(import.meta.dirname, '../scripts/ci/playwright-smoke.mjs');
  const failed = spawnSync(process.execPath, [script, 'bad-action'], { encoding: 'utf8' });
  assert.equal(failed.status, 1);
  assert.match(failed.stderr, /CI setup bad-action failed after \d+ms: Unknown smoke setup command/);
});

test('unknown setup subcommands fail closed', async () => {
  await assert.rejects(cli('do-everything', { core: '/unused', module: '/unused', env: {} }), /Unknown smoke setup command/);
  await assert.rejects(cli('expose-assets', { core: '/unused', module: '/unused', env: {} }), /requires APP_ENV=testing/);
});
