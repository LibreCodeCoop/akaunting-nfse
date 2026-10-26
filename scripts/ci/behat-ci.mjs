// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { prepareEnvironment, provisionFixtureData, startServer, stopServer } from './playwright-smoke.mjs';

const moduleRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const coreRoot = path.resolve(moduleRoot, '../..');
const fixturePath = path.join(moduleRoot, 'build/behat/fixture-ids.json');
const actorPath = path.join(moduleRoot, 'scripts/ci/behat-actors.json');
const fixedUrl = 'http://127.0.0.1:8082';
const requiredIds = ['NFSE_E2E_PENDING_INVOICE_ID', 'NFSE_BEHAT_OTHER_COMPANY_ID', 'NFSE_BEHAT_READY_INVOICE_ID'];

export function validateDisposableSetup({ core = coreRoot, module = moduleRoot, env = process.env, exists = fs.existsSync } = {}) {
  if (env.APP_ENV !== 'testing' || env.DB_CONNECTION !== 'sqlite' || env.NFSE_TEST_HARNESS !== '1') {
    throw new Error('Behat requires APP_ENV=testing, DB_CONNECTION=sqlite and NFSE_TEST_HARNESS=1.');
  }
  if (env.NFSE_BEHAT_DISPOSABLE !== '1') {
    throw new Error('Explicit NFSE_BEHAT_DISPOSABLE=1 is required before destructive setup.');
  }
  if (path.resolve(module) !== path.resolve(core, 'modules/Nfse') ||
      !exists(path.join(core, 'artisan')) || !exists(path.join(core, '.env.testing')) ||
      !exists(path.join(core, '.git')) || !exists(path.join(module, 'behat.php'))) {
    throw new Error('Use a separate Akaunting Git checkout containing the NFS-e module at modules/Nfse.');
  }
  const database = path.resolve(core, 'database/playwright.sqlite');
  if (env.DB_DATABASE && path.resolve(env.DB_DATABASE) !== database) {
    throw new Error('DB_DATABASE must be the disposable checkout SQLite path.');
  }
  if (env.APP_URL && env.APP_URL !== fixedUrl) {
    throw new Error('Akaunting local HTTP fixture must use ' + fixedUrl);
  }
}

/** Enforce a single SQLite database for migrations, seeders, fixtures and HTTP. */
export function disposableRuntimeEnv(core, env) {
  const database = path.resolve(core, 'database/playwright.sqlite');
  if (env.DB_DATABASE && path.resolve(env.DB_DATABASE) !== database) {
    throw new Error('DB_DATABASE cannot point outside the disposable checkout.');
  }
  return { ...env, DB_DATABASE: database };
}

export function fixtureIds(primary, secondary) {
  const ids = { ...primary };
  if (secondary?.other_company_id !== undefined) ids.NFSE_BEHAT_OTHER_COMPANY_ID = secondary.other_company_id;
  if (secondary?.ready_invoice_id !== undefined) ids.NFSE_BEHAT_READY_INVOICE_ID = secondary.ready_invoice_id;
  for (const [key, value] of Object.entries(ids)) {
    if (!/^NFSE_(?:BEHAT|E2E)_[A-Z0-9_]+$/.test(key) ||
        !/^[1-9]\d*$/.test(String(value)) || !Number.isSafeInteger(Number(value))) {
      throw new Error('Invalid synthetic fixture ID: ' + key);
    }
  }
  for (const name of requiredIds) {
    if (!Object.hasOwn(ids, name)) throw new Error('Missing synthetic fixture ID: ' + name);
  }
  return Object.fromEntries(Object.entries(ids).map(([key, value]) => [key, String(value)]));
}

export function readActors(io = fs) {
  const actors = JSON.parse(io.readFileSync(actorPath, 'utf8'));
  for (const name of ['administrator', 'other-company-administrator', 'no-permission']) {
    const user = actors[name];
    if (typeof user?.email !== 'string' || !user.email.endsWith('@example.test') ||
        typeof user?.password !== 'string' || !user.password) {
      throw new Error('Invalid synthetic actor: ' + name);
    }
  }
  return actors;
}

export function buildBehatEnv(env, ids, actors) {
  const required = fixtureIds(ids, {});
  const result = {
    ...env,
    ...required,
    NFSE_BEHAT_BASE_URL: fixedUrl,
    NFSE_BEHAT_COMPANY_ID: '1',
  };
  for (const [name, prefix] of [
    ['administrator', 'NFSE_BEHAT'],
    ['other-company-administrator', 'NFSE_BEHAT_OTHER'],
    ['no-permission', 'NFSE_BEHAT_DENIED'],
  ]) {
    result[prefix + '_EMAIL'] = actors[name].email;
    result[prefix + '_PASSWORD'] = actors[name].password;
  }
  return result;
}

export function redactDiagnostics(source, actors) {
  const sensitive = [
    ...Object.values(actors).map(actor => actor.password),
    'nfse-test-password',
    'deterministic-test-token',
  ].filter(Boolean);
  return sensitive.reduce((text, secret) => text.replaceAll(secret, '[REDACTED]'), source);
}

export function behatArgs(random = false) {
  const args = [
    '--config=behat.php', '--strict', '--no-colors', '--no-snippets',
    '--tag-expression=not @external',
  ];
  if (random) return [...args, '--order=random', '--format=progress'];
  return [...args, '--format=progress', '--out=std', '--format=junit', '--out=build/behat-junit'];
}

export async function executeContracts({ start, stop, invoke, env, clock = Date.now, log = console.log }) {
  await start();
  try {
    const startTime = clock();
    invoke(false, env);
    log('Behat serial wall-clock: ' + Math.round((clock() - startTime) / 1000) + ' seconds');
    invoke(true, env);
  } finally {
    await stop();
  }
}

function command(program, args, cwd, env = process.env, output = 'inherit') {
  return execFileSync(program, args, {
    cwd, env, encoding: 'utf8', timeout: 600_000,
    stdio: ['ignore', output, 'inherit'],
  });
}

export function writeFixtureFile(ids, file = fixturePath, io = fs) {
  io.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
  if (io.existsSync(file) && io.lstatSync(file).isSymbolicLink()) {
    throw new Error('Refusing to overwrite a symlink fixture file.');
  }
  io.writeFileSync(file, JSON.stringify(ids) + '\n', { mode: 0o600 });
  io.chmodSync(file, 0o600);
}

export function loadFixtureFile(file = fixturePath, io = fs) {
  const stat = io.lstatSync(file);
  if (!stat.isFile() || (stat.mode & 0o077) !== 0) {
    throw new Error('The fixture ID file must be a private regular file.');
  }
  return fixtureIds(JSON.parse(io.readFileSync(file, 'utf8')), {});
}

function stage(name, work) {
  console.log('Behat CI: ' + name);
  return work();
}

export function prepare({ core = coreRoot, module = moduleRoot, env = process.env } = {}) {
  validateDisposableSetup({ core, module, env });
  const scoped = disposableRuntimeEnv(core, env);
  stage('prepare disposable application configuration', () => prepareEnvironment(core));
  stage('install Akaunting runtime', () => command('composer', ['test'], core, scoped));
  stage('install NFS-e scoped runtime', () => {
    command('composer', ['install', '--prefer-dist', '--no-progress', '--no-interaction', '--no-scripts'], module, scoped);
    command('composer', ['thirdparty:build:prod'], module, scoped);
    command('composer', ['--working-dir=vendor-bin/behat', 'install', '--prefer-dist', '--no-progress', '--no-interaction'], module, scoped);
  });
  stage('initialize disposable SQLite and synthetic company', () => {
    command('php', ['artisan', 'migrate:fresh', '--force'], core, scoped);
    command('php', ['artisan', 'db:seed', '--class=\\Database\\Seeds\\TestCompany', '--force'], core, scoped);
  });
  const first = stage('provision shared NFS-e synthetic harness', () => provisionFixtureData(core, scoped));
  const output = stage('provision Behat tenant and fiscal actor fixtures', () =>
    command('php', ['scripts/ci/behat-fixtures.php'], module, scoped, 'pipe'));
  // Only fixture IDs are persisted; synthetic passwords stay in source fixtures.
  const ids = fixtureIds(first, JSON.parse(output.trim()));
  writeFixtureFile(ids, path.join(module, 'build/behat/fixture-ids.json'));
  return ids;
}

export async function run({ core = coreRoot, module = moduleRoot, env = process.env } = {}) {
  validateDisposableSetup({ core, module, env });
  const runtimeEnv = disposableRuntimeEnv(core, env);
  const ids = loadFixtureFile(path.join(module, 'build/behat/fixture-ids.json'));
  const actors = readActors();
  const scoped = buildBehatEnv(runtimeEnv, ids, actors);
  const report = path.join(module, 'build/behat/server.log');
  try {
    await executeContracts({
      start: () => startServer(core, { env: runtimeEnv }),
      stop: () => stopServer(core),
      invoke: random => {
        fs.mkdirSync(path.join(module, 'build/behat-junit'), { recursive: true });
        command(path.join(module, 'vendor-bin/behat/vendor/bin/behat'), behatArgs(random), module, scoped);
      },
      env: scoped,
    });
  } catch (error) {
    const source = path.join(core, 'storage/logs/playwright-server.log');
    if (fs.existsSync(source)) {
      fs.mkdirSync(path.dirname(report), { recursive: true });
      fs.writeFileSync(report, redactDiagnostics(fs.readFileSync(source, 'utf8').slice(-100_000), actors), { mode: 0o600 });
    }
    throw new Error('Behat HTTP contract execution failed; inspect the scenario and sanitized server report.', { cause: error });
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const tasks = { prepare, run };
  const action = process.argv[2];
  if (!Object.hasOwn(tasks, action)) {
    console.error('Usage: node scripts/ci/behat-ci.mjs prepare|run');
    process.exitCode = 2;
  } else {
    try {
      await tasks[action]();
    } catch (error) {
      console.error(redactDiagnostics(error.message, readActors()));
      process.exitCode = 1;
    }
  }
}
