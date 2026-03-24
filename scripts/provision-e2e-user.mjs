import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const argv = new Set(process.argv.slice(2));

if (argv.has('--help')) {
  process.stdout.write([
    'Usage: node scripts/provision-e2e-user.mjs [--dry-run]',
    '',
    'Preferred environment:',
    '  NFSE_E2E_ARTISAN_COMMAND',
    '',
    'Fallback environment for remote HTTP bootstrap:',
    '  NFSE_E2E_BASE_URL',
    '  NFSE_E2E_ADMIN_EMAIL',
    '  NFSE_E2E_ADMIN_PASSWORD',
    '',
    'Optional environment:',
    '  NFSE_E2E_COMPANY_ID',
    '  NFSE_E2E_EMAIL',
    '  NFSE_E2E_PASSWORD',
    '  NFSE_E2E_USER_NAME',
    '  NFSE_E2E_ROLE_ID',
    '  NFSE_E2E_LANDING_PAGE',
  ].join('\n'));
  process.exit(0);
}

const dryRun = argv.has('--dry-run');
const companyId = process.env.NFSE_E2E_COMPANY_ID ?? '1';
const e2eEmail = process.env.NFSE_E2E_EMAIL ?? `nfse-e2e+${Date.now()}@example.test`;
const e2ePassword = process.env.NFSE_E2E_PASSWORD ?? generatePassword();
const e2eUserName = process.env.NFSE_E2E_USER_NAME ?? 'NFS-e E2E';
const artisanCommand = process.env.NFSE_E2E_ARTISAN_COMMAND ?? detectArtisanCommand();

if (dryRun) {
  emitEnv('NFSE_E2E_EMAIL', e2eEmail);
  emitEnv('NFSE_E2E_PASSWORD', e2ePassword);
  emitEnv('NFSE_E2E_COMPANY_ID', companyId);
  process.exit(0);
}

if (artisanCommand) {
  const provisioned = await provisionViaArtisan({
    artisanCommand,
    companyId,
    email: e2eEmail,
    password: e2ePassword,
    userName: e2eUserName,
    role: process.env.NFSE_E2E_ROLE_ID,
    landingPage: process.env.NFSE_E2E_LANDING_PAGE,
  });

  emitEnv('NFSE_E2E_EMAIL', provisioned.email);
  emitEnv('NFSE_E2E_PASSWORD', provisioned.password);
  emitEnv('NFSE_E2E_COMPANY_ID', provisioned.company_id);
  process.exit(0);
}

const baseUrl = requireEnv('NFSE_E2E_BASE_URL');
const adminEmail = requireEnv('NFSE_E2E_ADMIN_EMAIL');
const adminPassword = requireEnv('NFSE_E2E_ADMIN_PASSWORD');
const session = new SessionClient(baseUrl);

await login(session, adminEmail, adminPassword);

const createPage = await session.get(`/${companyId}/users/create`);
const createPageBody = await createPage.text();

assertStatus(createPage.status, [200], 'Unable to open user creation page after admin login.');

const csrfToken = extractHiddenInputValue(createPageBody, '_token') ?? extractMetaCsrfToken(createPageBody);
if (!csrfToken) {
  throw new Error('Could not find CSRF token in /users/create page.');
}

const roleId = process.env.NFSE_E2E_ROLE_ID ?? extractFirstSelectOption(createPageBody, 'roles');
if (!roleId) {
  throw new Error('Could not determine a role id for the E2E user. Set NFSE_E2E_ROLE_ID.');
}

const landingPage = process.env.NFSE_E2E_LANDING_PAGE
  ?? extractFirstSelectOption(createPageBody, 'landing_page')
  ?? 'dashboard';

const payload = new URLSearchParams();
payload.set('_token', csrfToken);
payload.set('name', e2eUserName);
payload.set('email', e2eEmail);
payload.set('change_password', '1');
payload.set('current_password', adminPassword);
payload.set('password', e2ePassword);
payload.set('password_confirmation', e2ePassword);
payload.append('companies[]', companyId);
payload.set('roles', roleId);
payload.set('landing_page', landingPage);
payload.set('enabled', '1');

const createResponse = await session.post(`/${companyId}/users`, payload, {
  Accept: 'application/json',
  'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
  Referer: `${session.baseUrl}/${companyId}/users/create`,
  'X-Requested-With': 'XMLHttpRequest',
});

assertStatus(createResponse.status, [200, 201], 'User creation request failed.');

const createResult = await createResponse.json();
if (!createResult || createResult.success !== true) {
  const message = typeof createResult?.message === 'string'
    ? createResult.message
    : 'Unknown failure while provisioning Playwright E2E user.';
  throw new Error(message);
}

emitEnv('NFSE_E2E_EMAIL', e2eEmail);
emitEnv('NFSE_E2E_PASSWORD', e2ePassword);
emitEnv('NFSE_E2E_COMPANY_ID', companyId);

async function provisionViaArtisan({ artisanCommand, companyId, email, password, userName, role, landingPage }) {
  const command = `${artisanCommand} nfse:test-user:provision --json --company-id=${shellEscape(companyId)} --email=${shellEscape(email)} --password=${shellEscape(password)} --name=${shellEscape(userName)}${role ? ` --role=${shellEscape(role)}` : ''}${landingPage ? ` --landing-page=${shellEscape(landingPage)}` : ''}`;
  const { stdout, stderr } = await execShellCommand(command);
  const output = `${stdout}\n${stderr}`.trim();
  const payloadLine = output
    .split(/\r?\n/)
    .map((line) => line.trim())
    .reverse()
    .find((line) => line.startsWith('{') && line.endsWith('}'));

  if (!payloadLine) {
    throw new Error(`Artisan provisioning did not return JSON output.\n${output}`.trim());
  }

  const payload = JSON.parse(payloadLine);

  if (payload.error) {
    throw new Error(payload.error);
  }

  return payload;
}

async function execShellCommand(command) {
  return execFileAsync('sh', ['-lc', command], {
    cwd: path.resolve(import.meta.dirname, '..'),
    env: process.env,
    maxBuffer: 1024 * 1024,
  });
}

function requireEnv(name) {
  const value = process.env[name];

  if (!value) {
    throw new Error(`Missing required environment variable: ${name}`);
  }

  return value;
}

function emitEnv(name, value) {
  process.stdout.write(`${name}=${escapeEnvValue(value)}\n`);
}

function escapeEnvValue(value) {
  return String(value).replace(/\r/g, '').replace(/\n/g, '');
}

function generatePassword() {
  return `NfseE2E!${crypto.randomBytes(9).toString('base64url')}`;
}

function detectArtisanCommand() {
  const dockerRoot = findDockerRoot(path.resolve(import.meta.dirname, '..'));

  if (!dockerRoot) {
    return '';
  }

  return `cd ${shellEscape(dockerRoot)} && docker compose exec -T akaunting.php php artisan`;
}

function findDockerRoot(startDir) {
  let currentDir = startDir;

  while (true) {
    for (const fileName of ['compose.yml', 'compose.yaml', 'docker-compose.yml']) {
      const candidate = path.join(currentDir, fileName);

      if (fs.existsSync(candidate)) {
        return currentDir;
      }
    }

    const parentDir = path.dirname(currentDir);

    if (parentDir === currentDir) {
      return '';
    }

    currentDir = parentDir;
  }
}

function shellEscape(value) {
  return `'${String(value).replace(/'/g, `'\\''`)}'`;
}

function assertStatus(status, expected, message) {
  if (expected.includes(status)) {
    return;
  }

  throw new Error(`${message} HTTP ${status}.`);
}

async function login(sessionClient, email, password) {
  const loginPage = await sessionClient.get('/auth/login');
  const loginBody = await loginPage.text();

  assertStatus(loginPage.status, [200], 'Unable to open /auth/login before provisioning.');

  const csrfToken = extractHiddenInputValue(loginBody, '_token') ?? extractMetaCsrfToken(loginBody);
  if (!csrfToken) {
    throw new Error('Could not find CSRF token in login page.');
  }

  const payload = new URLSearchParams();
  payload.set('_token', csrfToken);
  payload.set('email', email);
  payload.set('password', password);

  const response = await sessionClient.post('/auth/login', payload, {
    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
    Referer: `${sessionClient.baseUrl}/auth/login`,
  });

  if (![302, 303].includes(response.status)) {
    throw new Error(`Admin login failed with HTTP ${response.status}.`);
  }

  const location = response.headers.get('location') ?? '';
  if (location.includes('/auth/login')) {
    throw new Error('Admin login was rejected; check NFSE_E2E_ADMIN_EMAIL and NFSE_E2E_ADMIN_PASSWORD.');
  }
}

function extractHiddenInputValue(html, inputName) {
  const escapedName = escapeRegExp(inputName);
  const inputPattern = new RegExp(`<input[^>]*name=["']${escapedName}["'][^>]*value=["']([^"']+)["']`, 'i');
  const directMatch = html.match(inputPattern);

  if (directMatch?.[1]) {
    return decodeHtml(directMatch[1]);
  }

  const reversePattern = new RegExp(`<input[^>]*value=["']([^"']+)["'][^>]*name=["']${escapedName}["']`, 'i');
  const reverseMatch = html.match(reversePattern);

  return reverseMatch?.[1] ? decodeHtml(reverseMatch[1]) : null;
}

function extractMetaCsrfToken(html) {
  const match = html.match(/<meta[^>]*name=["']csrf-token["'][^>]*content=["']([^"']+)["']/i);

  return match?.[1] ? decodeHtml(match[1]) : null;
}

function extractFirstSelectOption(html, selectName) {
  const escapedName = escapeRegExp(selectName);
  const selectPattern = new RegExp(`<select[^>]*name=["']${escapedName}(?:\[\])?["'][^>]*>([\s\S]*?)<\/select>`, 'i');
  const selectMatch = html.match(selectPattern);

  if (!selectMatch?.[1]) {
    return null;
  }

  const optionPattern = /<option[^>]*value=["']([^"']+)["'][^>]*>/gi;

  for (const optionMatch of selectMatch[1].matchAll(optionPattern)) {
    const value = decodeHtml(optionMatch[1]).trim();

    if (value !== '') {
      return value;
    }
  }

  return null;
}

function decodeHtml(value) {
  return value
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>');
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

class SessionClient {
  constructor(baseUrl) {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.cookieJar = new Map();
  }

  async get(path, headers = {}) {
    return this.request(path, { method: 'GET', headers });
  }

  async post(path, body, headers = {}) {
    return this.request(path, { method: 'POST', headers, body });
  }

  async request(path, init) {
    const response = await fetch(new URL(path, this.baseUrl), {
      ...init,
      redirect: 'manual',
      headers: {
        ...init.headers,
        ...(this.cookieJar.size ? { Cookie: this.cookieHeader() } : {}),
      },
    });

    this.storeCookies(response.headers);

    return response;
  }

  cookieHeader() {
    return Array.from(this.cookieJar.entries())
      .map(([name, value]) => `${name}=${value}`)
      .join('; ');
  }

  storeCookies(headers) {
    const cookieHeaders = typeof headers.getSetCookie === 'function'
      ? headers.getSetCookie()
      : splitSetCookieHeader(headers.get('set-cookie'));

    for (const cookieHeader of cookieHeaders) {
      const [pair] = cookieHeader.split(';', 1);

      if (!pair || !pair.includes('=')) {
        continue;
      }

      const separatorIndex = pair.indexOf('=');
      const name = pair.slice(0, separatorIndex).trim();
      const value = pair.slice(separatorIndex + 1).trim();

      if (name !== '') {
        this.cookieJar.set(name, value);
      }
    }
  }
}

function splitSetCookieHeader(value) {
  if (!value) {
    return [];
  }

  return value.split(/,(?=[^;,]+=)/g);
}
