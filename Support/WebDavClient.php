<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

final class WebDavClient
{
    /** @var callable(string, string, array<string, string>, string): array{0:int,1:string} */
    private $request;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        ?callable $request = null,
    ) {
        $this->request = $request ?? [$this, 'requestUsingStreams'];
    }

    public function put(string $path, string $content): void
    {
        [$status] = ($this->request)(
            'PUT',
            $this->buildUrl($path),
            $this->authHeaders(),
            $content,
        );

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('WebDAV PUT failed with HTTP status ' . $status);
        }
    }

    public function exists(string $path): bool
    {
        [$status] = ($this->request)(
            'HEAD',
            $this->buildUrl($path),
            $this->authHeaders(),
            '',
        );

        if ($status === 404) {
            return false;
        }

        if ($status >= 200 && $status < 300) {
            return true;
        }

        throw new \RuntimeException('WebDAV HEAD failed with HTTP status ' . $status);
    }

    /**
     * @param array<string, string> $headers
     * @return array{0:int,1:string}
     */
    private function requestUsingStreams(string $method, string $url, array $headers, string $body): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $responseBody = is_string($responseBody) ? $responseBody : '';

        $status = 0;
        $responseHeaders = $http_response_header ?? [];

        if (isset($responseHeaders[0]) && is_string($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches) === 1) {
            $status = (int) $matches[1];
        }

        return [$status, $responseBody];
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/octet-stream',
        ];

        if ($this->username !== null && $this->username !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . ($this->password ?? ''));
        }

        return $headers;
    }

    private function buildUrl(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
