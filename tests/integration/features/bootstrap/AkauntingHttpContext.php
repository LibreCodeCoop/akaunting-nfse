<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use Behat\Step\DataTable;
use Behat\Step\DocString;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;

/**
 * Reusable HTTP contract vocabulary for Akaunting.
 * Requests use the application's real web middleware, including sessions,
 * authorization and CSRF. No internal controller calls or test-only routes.
 */
abstract class AkauntingHttpContext implements Context
{
    private Client $http;
    private ?ResponseInterface $lastResponse = null;
    private ?string $csrf = null;
    private bool $lastLoginAccepted = false;

    public function __construct()
    {
        $this->resetHttpScenario();
    }

    #[BeforeScenario]
    public function resetHttpScenario(): void
    {
        $base = rtrim((string) (getenv('NFSE_BEHAT_BASE_URL') ?: 'http://127.0.0.1:8082'), '/');
        $this->http = new Client([
            'base_uri' => $base,
            'cookies' => new CookieJar(),
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => 15,
            // Explicitly do not send authentication or cookies to external URLs.
        ]);
        $this->csrf = null;
        $this->lastResponse = null;
        $this->lastLoginAccepted = false;
    }

    #[Given('the API client is a guest')]
    public function apiClientIsGuest(): void
    {
        $this->resetHttpScenario();
    }

    #[Given('the :actor synthetic Akaunting user is authenticated')]
    public function authenticateSyntheticUser(string $actor): void
    {
        $this->resetHttpScenario();

        $accounts = [
            'administrator' => ['NFSE_BEHAT_EMAIL', 'NFSE_BEHAT_PASSWORD'],
            'other-company-administrator' => ['NFSE_BEHAT_OTHER_EMAIL', 'NFSE_BEHAT_OTHER_PASSWORD'],
            'no-permission' => ['NFSE_BEHAT_DENIED_EMAIL', 'NFSE_BEHAT_DENIED_PASSWORD'],
        ];
        if (!isset($accounts[$actor])) {
            throw new RuntimeException('Unknown synthetic account: ' . $actor);
        }

        [$emailName, $passwordName] = $accounts[$actor];
        $email = getenv($emailName);
        $password = getenv($passwordName);
        if (!is_string($email) || $email === '' || !is_string($password) || $password === '') {
            throw new RuntimeException('Missing synthetic account configuration: ' . $actor);
        }

        $this->login($email, $password);
        if (!$this->lastLoginAccepted) {
            throw new RuntimeException(sprintf(
                'Synthetic %s authentication failed (HTTP %d).',
                $actor,
                $this->lastResponse?->getStatusCode() ?? 0
            ));
        }
    }

    #[When('I attempt authentication with an incorrect password')]
    public function attemptInvalidAuthentication(): void
    {
        $this->resetHttpScenario();
        $email = getenv('NFSE_BEHAT_EMAIL');
        if (!is_string($email) || $email === '') {
            throw new RuntimeException('Missing synthetic account configuration.');
        }
        // Deliberately invalid for the disposable CI user; never log passwords.
        $this->login($email, 'invalid-synthetic-password');
    }

    #[Then('authentication should be rejected')]
    public function authenticationRejected(): void
    {
        if ($this->lastLoginAccepted) {
            throw new RuntimeException('Invalid credentials unexpectedly authenticated a session.');
        }
    }

    #[When('I make a native API GET request to :path with synthetic :actor credentials')]
    public function nativeApiGetAs(string $path, string $actor): void
    {
        $accounts = [
            'administrator' => ['NFSE_BEHAT_EMAIL', 'NFSE_BEHAT_PASSWORD'],
            'other-company-administrator' => ['NFSE_BEHAT_OTHER_EMAIL', 'NFSE_BEHAT_OTHER_PASSWORD'],
            'no-permission' => ['NFSE_BEHAT_DENIED_EMAIL', 'NFSE_BEHAT_DENIED_PASSWORD'],
        ];
        if (!isset($accounts[$actor])) {
            throw new RuntimeException('Unknown synthetic account.');
        }
        [$emailKey, $passwordKey] = $accounts[$actor];
        $email = getenv($emailKey);
        $password = getenv($passwordKey);
        if (!is_string($email) || $email === '' || !is_string($password) || $password === '') {
            throw new RuntimeException('Missing synthetic API credentials.');
        }

        $this->resetHttpScenario();
        $this->sendJson('GET', $path, null, ['auth' => [$email, $password]]);
    }

    #[When('I make a :method JSON request to :path')]
    public function makeJsonRequest(string $method, string $path): void
    {
        $this->sendJson($method, $path);
    }

    #[When('I make a :method JSON request to :path with body:')]
    public function makeJsonRequestWithBody(string $method, string $path, DocString $document): void
    {
        $payload = json_decode($document->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('JSON request body must be an object or an array.');
        }
        $this->sendJson($method, $path, $payload);
    }

    #[Then('the API response status should be :status')]
    public function apiResponseStatus(int $status): void
    {
        $response = $this->response();
        if ($response->getStatusCode() !== $status) {
            throw new RuntimeException(sprintf(
                'Expected HTTP %d, received HTTP %d.',
                $status,
                $response->getStatusCode()
            ));
        }
    }

    #[Then('the API response should be JSON')]
    public function responseMustBeJson(): void
    {
        $response = $this->response();
        if (!str_contains(strtolower($response->getHeaderLine('Content-Type')), 'application/json')) {
            throw new RuntimeException('Expected application/json Content-Type.');
        }
        $this->json();
    }

    #[Then('the JSON response should have these field types:')]
    public function jsonFieldTypes(DataTable $fields): void
    {
        foreach ($fields->asMap() as $path => $type) {
            if (!is_string($type)) {
                throw new RuntimeException('Each JSON type must be supplied.');
            }
            $this->assertJsonType($path, $type);
        }
    }

    #[Then('the JSON value at :path should be :expected')]
    public function jsonValue(string $path, string $expected): void
    {
        $actual = $this->jsonAt($path);
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'JSON value at "%s" does not match expected string "%s".',
                $path,
                $expected
            ));
        }
    }

    #[Then('the JSON boolean at :path should be :expected')]
    public function jsonBoolean(string $path, string $expected): void
    {
        if (!in_array($expected, ['true', 'false'], true)) {
            throw new RuntimeException('Expected boolean must be true or false.');
        }
        if ($this->jsonAt($path) !== ($expected === 'true')) {
            throw new RuntimeException('JSON boolean differs from expected at "' . $path . '".');
        }
    }

    #[Then('the JSON array at :path should contain at most :count items')]
    public function jsonArrayAtMost(string $path, int $count): void
    {
        $data = $this->jsonAt($path);
        if (!is_array($data) || !array_is_list($data) || count($data) > $count) {
            throw new RuntimeException(sprintf('JSON "%s" must be an array with at most %d entries.', $path, $count));
        }
    }

    #[Then('the JSON array at :path should contain exactly :count item')]
    public function jsonArrayExactly(string $path, int $count): void
    {
        $entries = $this->jsonAt($path);
        if (!is_array($entries) || !array_is_list($entries) || count($entries) !== $count) {
            throw new RuntimeException(sprintf('JSON "%s" should contain exactly %d items.', $path, $count));
        }
    }

    #[Then('the JSON response should not include :path')]
    public function jsonNotInclude(string $path): void
    {
        $current = $this->json();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return;
            }
            $current = $current[$segment];
        }
        throw new RuntimeException('Unexpected JSON property: ' . $path);
    }

    protected function jsonAt(string $path): mixed
    {
        $data = $this->json();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                throw new RuntimeException('Missing JSON contract property: ' . $path);
            }
            $data = $data[$segment];
        }
        return $data;
    }

    protected function fixtureId(string $fixture): int
    {
        $names = [
            'company' => 'NFSE_BEHAT_COMPANY_ID',
            'other-company' => 'NFSE_BEHAT_OTHER_COMPANY_ID',
            'pending-invoice' => 'NFSE_E2E_PENDING_INVOICE_ID',
            'ready-invoice' => 'NFSE_BEHAT_READY_INVOICE_ID',
        ];
        if (!isset($names[$fixture])) {
            throw new RuntimeException('Unknown synthetic fixture: ' . $fixture);
        }
        $raw = getenv($names[$fixture]);
        if (($raw === false || $raw === '') && $fixture === 'company') {
            $raw = '1';
        }
        if (!is_string($raw) || preg_match('/^[1-9]\d*$/', $raw) !== 1) {
            throw new RuntimeException('Missing numeric synthetic fixture: ' . $fixture);
        }
        return (int) $raw;
    }

    private function assertJsonType(string $path, string $type): void
    {
        $data = $this->jsonAt($path);
        $valid = match ($type) {
            'string' => is_string($data),
            'integer' => is_int($data),
            'boolean' => is_bool($data),
            'null' => $data === null,
            'array' => is_array($data) && array_is_list($data),
            'object' => is_array($data) && !array_is_list($data),
            default => throw new RuntimeException('Unsupported JSON type: ' . $type),
        };
        if (!$valid) {
            throw new RuntimeException(sprintf('JSON "%s" must have type "%s".', $path, $type));
        }
    }

    private function response(): ResponseInterface
    {
        if ($this->lastResponse === null) {
            throw new RuntimeException('An HTTP request step must precede response assertions.');
        }
        return $this->lastResponse;
    }

    /** @return array<string,mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->response()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Expected a JSON object/array response.');
        }
        return $decoded;
    }

    private function sendJson(string $method, string $path, ?array $body = null, array $extraOptions = []): void
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
            throw new RuntimeException('Unsupported HTTP method.');
        }
        $path = preg_replace_callback(
            '/<(company_id|other_company_id|pending_invoice_id|ready_invoice_id)>/',
            fn (array $match): string => (string) $this->fixtureId(match ($match[1]) {
                'company_id' => 'company',
                'other_company_id' => 'other-company',
                'pending_invoice_id' => 'pending-invoice',
                'ready_invoice_id' => 'ready-invoice',
            }),
            $path,
        );
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '://')) {
            throw new RuntimeException('Only relative in-application HTTP paths are permitted.');
        }
        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
        if (!in_array($method, ['GET'], true) && $this->csrf !== null) {
            $headers['X-CSRF-TOKEN'] = $this->csrf;
        }
        $options = array_merge(['headers' => $headers], $extraOptions);
        if ($body !== null) {
            $options['json'] = $body;
        }
        $this->lastResponse = $this->http->request($method, $path, $options);
    }

    private function login(string $email, string $password): void
    {
        $page = $this->http->request('GET', '/auth/login', [
            'headers' => ['Accept' => 'text/html'],
        ]);
        if ($page->getStatusCode() !== 200 ||
            preg_match('/name="_token"\s+value="([^"]+)"/', (string) $page->getBody(), $match) !== 1) {
            throw new RuntimeException('Unable to obtain a synthetic login CSRF token.');
        }
        $this->csrf = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        $response = $this->http->request('POST', '/auth/login', [
            'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
            'form_params' => ['email' => $email, 'password' => $password, '_token' => $this->csrf],
        ]);
        $this->lastResponse = $response;
        $data = json_decode((string) $response->getBody(), true);
        $this->lastLoginAccepted = ($response->getStatusCode() === 200 && is_array($data) &&
            ($data['success'] ?? false) === true);
        if (in_array($response->getStatusCode(), [302, 303], true) &&
            !str_contains($response->getHeaderLine('Location'), '/auth/login')) {
            $this->lastLoginAccepted = true;
        }
    }
}
