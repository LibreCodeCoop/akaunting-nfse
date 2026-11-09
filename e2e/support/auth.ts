// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, Page, Route, TestInfo } from '@playwright/test';

export async function loginToAkaunting(page: Page, testInfo: TestInfo): Promise<void> {
  const email = process.env.NFSE_E2E_EMAIL;
  const password = process.env.NFSE_E2E_PASSWORD;

  if (!email || !password) {
    testInfo.skip(true, 'Set NFSE_E2E_EMAIL and NFSE_E2E_PASSWORD to run Playwright E2E tests.');
    return;
  }

  await loginToAkauntingWithCredentials(page, email, password);
}

export async function loginToAkauntingWithCredentials(
  page: Page,
  email: string,
  password: string,
): Promise<void> {
  // The Akaunting login script redirects to /1 (dashboard). The disposable
  // fixture does not test that unrelated dashboard and can leave its initial
  // response unfinished, blocking browser login. Stub ONLY this transient
  // landing page after the real login POST; callers navigate their actual
  // authenticated target pages immediately afterward.
  const dashboardPath = '**/1';
  const dashboardLanding = async (route: Route): Promise<void> => {
    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: '<!doctype html><html><body><main>E2E authenticated landing</main></body></html>',
    });
  };
  await page.route(dashboardPath, dashboardLanding);
  try {
    await page.goto('/auth/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);

    const loginResponsePromise = page.waitForResponse((response) => {
      return response.request().method() === 'POST' && response.url().includes('/auth/login');
    });

    await page.locator('form[action*="auth/login"] button[type="submit"]').click();

    const loginResponse = await loginResponsePromise;

    try {
      await page.waitForURL((url) => !url.pathname.endsWith('/auth/login'), { timeout: 10_000 });
    } catch {
      // Some Akaunting setups return AJAX JSON and keep the current URL.
      // In this case we use the JSON payload to continue or fail fast.
    }

    if (/\/auth\/login$/.test(page.url())) {
      let payload: Record<string, unknown> | null = null;

      try {
        payload = (await loginResponse.json()) as Record<string, unknown>;
      } catch {
        payload = null;
      }

      if ((payload?.success as boolean | undefined) === true) {
        const redirectPath = typeof payload.redirect === 'string' && payload.redirect !== ''
          ? payload.redirect
          : '/';

        await page.goto(redirectPath, { waitUntil: 'domcontentloaded' });
      }
    }

    if (/\/auth\/login$/.test(page.url())) {
      const inlineError = page.locator('text=These credentials do not match our records.');
      const hasInlineError = await inlineError.count();

      if (hasInlineError > 0) {
        throw new Error('E2E login failed: credentials were rejected by Akaunting.');
      }

      const status = loginResponse.status();
      const contentType = loginResponse.headers()['content-type'] ?? '';
      const responseSummary = contentType.includes('application/json')
        ? JSON.stringify(payload)
        : (await loginResponse.text()).replace(/\s+/g, ' ').slice(0, 500);

      throw new Error(
        `E2E login did not complete (HTTP ${status}, ${contentType || 'unknown content type'}): ${responseSummary}`,
      );
    }

    // The real login POST and protected target requests remain authoritative.
    // Do not reread POST JSON after navigation: Chromium may have disposed of
    // its response body when the landing document replaced the login page.
    await expect(page).not.toHaveURL(/\/auth\/login$/);
    await expect(page.locator('body')).toBeVisible();
  } finally {
    await page.unroute(dashboardPath, dashboardLanding);
  }
}
