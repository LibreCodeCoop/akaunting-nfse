// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {
  validateDisposableSetup, disposableRuntimeEnv, fixtureIds, readActors, buildBehatEnv,
  redactDiagnostics, behatArgs, executeContracts, writeFixtureFile, loadFixtureFile,
} from '../scripts/ci/behat-ci.mjs';
import { testedPhpVersions } from '../scripts/ci/php-matrix.mjs';
import { provisionFixtureData } from '../scripts/ci/playwright-smoke.mjs';

const ids = {
  NFSE_E2E_PENDING_INVOICE_ID: '101',
  NFSE_BEHAT_OTHER_COMPANY_ID: '2',
  NFSE_BEHAT_READY_INVOICE_ID: '202',
};
const environment = {
  APP_ENV: 'testing', DB_CONNECTION: 'sqlite', NFSE_TEST_HARNESS: '1',
  NFSE_BEHAT_DISPOSABLE: '1',
};

test('Behat refuses unsafe database, production or non-disposable setup', () => {
  const core = '/tmp/ephemeral/akaunting';
  const module = core + '/modules/Nfse';
  const options = { core, module, env: environment, exists: () => true };
  assert.doesNotThrow(() => validateDisposableSetup(options));
  for (const invalid of [
    { APP_ENV: 'production' }, { DB_CONNECTION: 'mysql' },
    { NFSE_TEST_HARNESS: '0' }, { NFSE_BEHAT_DISPOSABLE: '0' },
    { DB_DATABASE: '/var/lib/mysql/data' }, { APP_URL: 'https://example.com' },
  ]) {
    assert.throws(() => validateDisposableSetup({ ...options, env: { ...environment, ...invalid } }));
  }
  assert.throws(() => validateDisposableSetup({ ...options, module: '/tmp/unrelated/Nfse' }));
  assert.throws(() => validateDisposableSetup({ ...options, exists: () => false }));
});

test('Migrations, seeders and HTTP use the same disposable SQLite path', () => {
  const core = '/tmp/ephemeral/akaunting';
  const configured = disposableRuntimeEnv(core, environment);
  assert.equal(configured.DB_DATABASE, core + '/database/playwright.sqlite');
  assert.equal(configured.APP_ENV, 'testing');
  assert.throws(() => disposableRuntimeEnv(core, { ...environment, DB_DATABASE: '/tmp/other.sqlite' }));
});

test('Behat fixture IDs are numeric and cannot inject environment assignments', () => {
  assert.deepEqual(fixtureIds({ NFSE_E2E_PENDING_INVOICE_ID: 101 }, {
    other_company_id: 2, ready_invoice_id: 202,
  }), ids);
  assert.throws(() => fixtureIds({ ...ids, NFSE_E2E_PENDING_INVOICE_ID: '1\nINJECT=value' }, {}));
  assert.throws(() => fixtureIds({ NFSE_E2E_PENDING_INVOICE_ID: 1 }, {}), /Missing/);
  assert.throws(() => fixtureIds({ ...ids, NFSE_BEHAT_READY_INVOICE_ID: 0 }, {}));
});

test('Only numeric fixture IDs are written to a private file; symlinks are rejected', t => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nfse-behat-fixtures-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  const file = path.join(dir, 'build', 'behat', 'ids.json');
  writeFixtureFile(ids, file);
  assert.equal(fs.statSync(file).mode & 0o077, 0);
  assert.deepEqual(loadFixtureFile(file), ids);
  assert.doesNotMatch(fs.readFileSync(file, 'utf8'), /password|token|@example.test/);
  fs.rmSync(file);
  fs.symlinkSync('/dev/null', file);
  assert.throws(() => writeFixtureFile(ids, file), /symlink/);
});

test('Synthetic actors stay in a single source and cannot be mistaken for production accounts', () => {
  const actors = readActors();
  const result = buildBehatEnv(environment, ids, actors);
  assert.equal(result.NFSE_BEHAT_OTHER_COMPANY_ID, '2');
  assert.equal(result.NFSE_BEHAT_READY_INVOICE_ID, '202');
  assert.equal(result.NFSE_BEHAT_EMAIL, actors.administrator.email);
  assert.equal(result.NFSE_BEHAT_DENIED_PASSWORD, actors['no-permission'].password);
  for (const actor of Object.values(actors)) {
    assert.match(actor.email, /@example\.test$/);
    assert.ok(actor.password);
  }
  assert.doesNotMatch(redactDiagnostics(Object.values(actors).map(a => a.password).join(' '), actors),
    /NfseE2E|NfseBehatOther/);
  assert.match(redactDiagnostics('nfse-test-password deterministic-test-token', actors),
    /\[REDACTED\] \[REDACTED\]/);
});

test('Serial failure prevents random run and always terminates the test server', async () => {
  const calls = [];
  const settings = {
    start: async () => calls.push('start'),
    stop: async () => calls.push('stop'),
    env: environment,
    log: () => {},
    clock: () => 1000,
    invoke: random => {
      calls.push(random ? 'random' : 'serial');
      if (!random) throw new Error('synthetic failed assertion');
    },
  };
  await assert.rejects(executeContracts(settings), /synthetic failed/);
  assert.deepEqual(calls, ['start', 'serial', 'stop']);
  calls.length = 0;
  await executeContracts({ ...settings, invoke: random => calls.push(random ? 'random' : 'serial') });
  assert.deepEqual(calls, ['start', 'serial', 'random', 'stop']);
});

test('Behat preserves strict serial JUnit and independently randomized execution', () => {
  assert.ok(behatArgs(false).includes('--format=junit'));
  assert.ok(behatArgs(true).includes('--order=random'));
  assert.ok(!behatArgs(false).includes('--order=random'));
  assert.ok(behatArgs(true).includes('--tag-expression=not @external'));
});

test('Tested PHP minors are central, unique, ordered, and allowed by Composer', () => {
  const manifest = { require: { php: '^8.2' }, extra: { ci: { php_versions: ['8.2', '8.3', '8.4'] } } };
  assert.deepEqual(testedPhpVersions(manifest), ['8.2', '8.3', '8.4']);
  assert.throws(() => testedPhpVersions({ ...manifest, extra: { ci: { php_versions: ['8.1'] } } }));
  assert.throws(() => testedPhpVersions({ ...manifest, extra: { ci: { php_versions: ['8.3', '8.3'] } } }));
  assert.throws(() => testedPhpVersions({ ...manifest, extra: { ci: { php_versions: ['8.4', '8.3'] } } }));
  assert.throws(() => testedPhpVersions({ ...manifest, require: { php: '*' } }));
});

test('Existing fiscal harness can be provisioned without a GitHub environment file', () => {
  const mockedIds = {
    pending_invoice_id: 1, item_validation_item_id: 2,
    item_legacy_id: 3, item_failing_id: 4,
  };
  const calls = [];
  const run = (program, args) => {
    calls.push([program, args]);
    return args.includes('nfse:test-harness:provision') ? JSON.stringify(mockedIds) : '';
  };
  const result = provisionFixtureData('/tmp/test-owned-core', environment, run);
  assert.equal(result.NFSE_E2E_PENDING_INVOICE_ID, '1');
  assert.equal(calls.length, 2);
  assert.throws(() => provisionFixtureData('/tmp/test-owned-core', { ...environment, APP_ENV: 'production' }, run));
});
