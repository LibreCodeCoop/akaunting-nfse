<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;

final class FeatureContext implements Context
{
    private Client $client;
    private CookieJar $cookies;
    private ?ResponseInterface $response = null;
    private string $companyId;
    private ?string $csrfToken = null;

    public function __construct()
    {
        $this->cookies = new CookieJar();

        $baseUrl = rtrim((string) getenv('NFSE_BEHAT_BASE_URL') ?: 'http://localhost:8082', '/');
        $this->companyId = (string) getenv('NFSE_BEHAT_COMPANY_ID') ?: '1';

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'cookies' => $this->cookies,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    #[Given('I am authenticated in Akaunting as configured user')]
    public function iAmAuthenticatedInAkauntingAsConfiguredUser(): void
    {
        $this->csrfToken = null;

        $email = getenv('NFSE_BEHAT_EMAIL');
        $password = getenv('NFSE_BEHAT_PASSWORD');

        $this->ensure($email !== false, 'Set NFSE_BEHAT_EMAIL to run authenticated Behat scenarios.');
        $this->ensure($password !== false, 'Set NFSE_BEHAT_PASSWORD to run authenticated Behat scenarios.');

        $loginPage = $this->client->request('GET', '/auth/login');
        $this->ensure(
            $loginPage->getStatusCode() === 200,
            'Unable to open /auth/login before authentication.'
        );

        $csrf = $this->extractCsrfToken((string) $loginPage->getBody());

        $this->response = $this->client->request('POST', '/auth/login', [
            'form_params' => [
                '_token' => $csrf,
                'email' => (string) $email,
                'password' => (string) $password,
            ],
        ]);

            $statusCode = $this->response->getStatusCode();

            // Akaunting returns HTTP 200 with JSON {success:true, redirect:...} on AJAX login success.
            if ($statusCode === 200) {
                $body = (array) json_decode((string) $this->response->getBody(), true);
                $this->ensure(
                    ($body['success'] ?? false) === true,
                    'Expected authentication redirect after POST /auth/login.'
                );

                $this->ensureBaselineNfseSettings();

                return;
            }

            $this->ensure(
                in_array($statusCode, [302, 303], true),
                'Expected authentication redirect after POST /auth/login.'
            );

        $this->ensureBaselineNfseSettings();
    }

    #[Given('I use company id :companyId')]
    public function iUseCompanyId(string $companyId): void
    {
        $this->companyId = $companyId;
    }

    #[When('I send :method request to :path')]
    public function iSendRequestTo(string $method, string $path): void
    {
        $this->response = $this->request($method, $path);
    }

    #[When('I send :method request to :path with form data:')]
    public function iSendRequestToWithFormData(string $method, string $path, TableNode $table): void
    {
        $formData = [];
        foreach ($table->getRowsHash() as $key => $value) {
            $formData[$key] = $value;
        }

        $this->response = $this->request($method, $path, ['form_params' => $formData]);
    }

    #[When('I upload fixture :fixtureName to :path using password :password')]
    public function iUploadFixtureToUsingPassword(string $fixtureName, string $path, string $password): void
    {
        $fixturePath = __DIR__ . '/../assets/' . $fixtureName;
        $this->ensure(is_file($fixturePath), sprintf('Missing fixture file: %s', $fixturePath));

        $this->ensureCsrfToken();

        $this->response = $this->request('POST', $path, [
            'multipart' => [
                [
                    'name' => '_token',
                    'contents' => (string) $this->csrfToken,
                ],
                [
                    'name' => 'pfx_password',
                    'contents' => $password,
                ],
                [
                    'name' => 'pfx_file',
                    'contents' => fopen($fixturePath, 'rb'),
                    'filename' => basename($fixturePath),
                ],
            ],
        ]);
    }

    #[Then('the response status should be :statusCode')]
    public function theResponseStatusShouldBe(int $statusCode): void
    {
        $this->ensureResponse();
        $this->ensure(
            $this->response->getStatusCode() === $statusCode,
            sprintf('Expected HTTP %d, got %d.', $statusCode, $this->response->getStatusCode())
        );
    }

    #[Then('the response status should be one of :statusList')]
    public function theResponseStatusShouldBeOneOf(string $statusList): void
    {
        $this->ensureResponse();

        $expected = array_map(
            static fn (string $status): int => (int) trim($status),
            explode(',', $statusList)
        );

        $this->ensure(
            in_array($this->response->getStatusCode(), $expected, true),
            sprintf('Expected one of [%s], got %d.', implode(', ', $expected), $this->response->getStatusCode())
        );
    }

    #[Then('the response should redirect to :path')]
    public function theResponseShouldRedirectTo(string $path): void
    {
        $this->ensureResponse();

        $location = $this->response->getHeaderLine('Location');
        $this->ensure($location !== '', 'Response has no Location header.');
        $this->ensure(
            str_contains($location, $this->replaceCompanyPlaceholder($path)),
            sprintf('Expected redirect location to contain "%s", got "%s".', $this->replaceCompanyPlaceholder($path), $location)
        );
    }

    #[Then('the response body should contain :text')]
    public function theResponseBodyShouldContain(string $text): void
    {
        $this->ensureResponse();

        $body = (string) $this->response->getBody();
        $this->ensure(
            str_contains($body, $text),
            sprintf('Expected response body to contain "%s".', $text)
        );
    }

    #[Then('the response should mark :elementId as configured :configuredValue')]
    public function theResponseShouldMarkElementAsConfigured(string $elementId, string $configuredValue): void
    {
        $this->ensureResponse();

            // Use a regex so the check succeeds regardless of other HTML attributes
            // that may appear between id="..." and data-configured="..." on the same element.
            $pattern = '/id="' . preg_quote($elementId, '/') . '"[^>]*\bdata-configured="' . preg_quote($configuredValue, '/') . '"/';
            $body = (string) $this->response->getBody();

            $this->ensure(
                (bool) preg_match($pattern, $body),
                sprintf('Expected element id="%s" to have data-configured="%s".', $elementId, $configuredValue)
            );
    }

    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $resolvedPath = $this->replaceCompanyPlaceholder($path);
        $normalizedMethod = strtoupper($method);

        if (in_array($normalizedMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !isset($options['multipart'])) {
            $this->ensureCsrfToken();

            $formParams = $options['form_params'] ?? [];
            $formParams['_token'] ??= $this->csrfToken;
            $options['form_params'] = $formParams;
        }

        return $this->client->request($normalizedMethod, $resolvedPath, $options);
    }

    private function ensureCsrfToken(): void
    {
        if ($this->csrfToken !== null) {
            return;
        }

        $response = $this->client->request('GET', $this->replaceCompanyPlaceholder('/<company_id>/nfse/settings'));
        $this->ensure(
            $response->getStatusCode() === 200,
            'Unable to load settings page to extract CSRF token. Ensure the user is authenticated.'
        );

        $this->csrfToken = $this->extractCsrfToken((string) $response->getBody());
    }

    private function ensureBaselineNfseSettings(): void
    {
        $settingsPath = $this->replaceCompanyPlaceholder('/<company_id>/nfse/settings');
        $response = $this->client->request('GET', $settingsPath);

        $this->ensure(
            $response->getStatusCode() === 200,
            'Unable to load settings page after authentication.'
        );

        $body = (string) $response->getBody();
        $this->csrfToken = $this->extractCsrfToken($body);

        if (str_contains($body, 'nfse[cnpj_prestador]') && str_contains($body, 'nfse[item_lista_servico]')) {
            return;
        }

        $seedResponse = $this->client->request('PATCH', $settingsPath, [
            'form_params' => [
                '_token' => $this->csrfToken,
                'nfse[cnpj_prestador]' => '12345678901234',
                'nfse[uf]' => 'SP',
                'nfse[municipio_nome]' => 'Sao Paulo',
                'nfse[municipio_ibge]' => '3550308',
                'nfse[item_lista_servico]' => '0107',
                'nfse[aliquota]' => '5.00',
                'nfse[sandbox_mode]' => '1',
                'nfse[bao_addr]' => 'https://vault.local.test',
                'nfse[bao_mount]' => 'nfse',
                'nfse[bao_token]' => 'token-behat-seed',
                'nfse[bao_role_id]' => 'role-behat-seed',
                'nfse[bao_secret_id]' => 'secret-behat-seed',
            ],
        ]);

        $this->ensure(
            in_array($seedResponse->getStatusCode(), [302, 303], true),
            sprintf('Expected baseline NFS-e settings seed to redirect, got %d.', $seedResponse->getStatusCode())
        );

        $confirmedResponse = $this->client->request('GET', $settingsPath);
        $confirmedBody = (string) $confirmedResponse->getBody();

        $this->ensure(
            $confirmedResponse->getStatusCode() === 200,
            'Unable to reload settings page after seeding baseline NFS-e settings.'
        );

        $this->ensure(
            str_contains($confirmedBody, 'nfse[cnpj_prestador]') && str_contains($confirmedBody, 'nfse[item_lista_servico]'),
            'Baseline NFS-e settings seed did not unlock the fiscal settings form.'
        );

        $this->csrfToken = $this->extractCsrfToken($confirmedBody);
    }

    private function extractCsrfToken(string $html): string
    {
        if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $matches) !== 1) {
            throw new RuntimeException('Could not extract CSRF token from HTML response.');
        }

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    }

    private function replaceCompanyPlaceholder(string $path): string
    {
        return str_replace('<company_id>', $this->companyId, $path);
    }

    private function ensureResponse(): void
    {
        $this->ensure($this->response !== null, 'No HTTP response available. Call a request step first.');
    }

    private function ensure(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
