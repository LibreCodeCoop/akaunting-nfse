<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\Http;

final class WebDavClient
{
    /** @var callable(string, string, array<string, string>, string): array{0:int,1:string} */
    private $request;

    /** @var array<string, true> */
    private array $knownDirectories = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly float $timeoutSeconds = 20.0,
        ?callable $request = null,
    ) {
        if ($this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('WebDAV timeout must be greater than zero.');
        }

        $this->request = $request ?? [$this, 'requestUsingHttpClient'];
    }

    public function put(string $path, string $content): void
    {
        $status = $this->putOnce($path, $content);

        if ($status >= 200 && $status < 300) {
            return;
        }

        if (!in_array($status, [404, 409], true)) {
            throw new \RuntimeException('WebDAV PUT failed with HTTP status ' . $status);
        }

        $this->ensureParentDirectories($path);
        $status = $this->putOnce($path, $content);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('WebDAV PUT failed with HTTP status ' . $status);
        }
    }

    private function putOnce(string $path, string $content): int
    {
        [$status] = ($this->request)(
            'PUT',
            $this->buildUrl($path),
            $this->authHeaders(),
            $content,
        );

        return $status;
    }

    public function get(string $path): string
    {
        [$status, $body] = ($this->request)(
            'GET',
            $this->buildUrl($path),
            $this->authHeaders(),
            '',
        );

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('WebDAV GET failed with HTTP status ' . $status);
        }

        return $body;
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
    private function requestUsingHttpClient(
        string $method,
        string $url,
        array $headers,
        string $body,
    ): array {
        try {
            $timeoutSeconds = (int) ceil($this->timeoutSeconds);
            $connectTimeoutSeconds = min(15, $timeoutSeconds);

            $request = Http::withHeaders($headers)
                ->connectTimeout($connectTimeoutSeconds)
                ->timeout($timeoutSeconds);

            if ($body !== '') {
                $request = $request->withBody(
                    $body,
                    $headers['Content-Type'] ?? 'application/octet-stream',
                );
            }

            $response = $request->send($method, $url);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'WebDAV ' . $method . ' transport failed: ' . $throwable->getMessage(),
                0,
                $throwable,
            );
        }

        return [$response->status(), $response->body()];
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
        $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');
        $segments = $normalizedPath === '' ? [] : explode('/', $normalizedPath);
        $encodedPath = implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), $segments));

        return rtrim($this->baseUrl, '/') . '/' . $encodedPath;
    }

    private function ensureParentDirectories(string $path): void
    {
        $normalizedPath = trim(str_replace('\\', '/', $path), '/');
        $segments = $normalizedPath === '' ? [] : explode('/', $normalizedPath);

        if (count($segments) <= 1) {
            return;
        }

        array_pop($segments);

        $directories = [];
        $current = '';

        foreach ($segments as $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;
            $directories[] = $current;
        }

        $deepestExistingIndex = -1;

        for ($index = count($directories) - 1; $index >= 0; $index--) {
            $directory = $directories[$index];

            if (isset($this->knownDirectories[$directory]) || $this->exists($directory)) {
                $deepestExistingIndex = $index;

                for ($knownIndex = 0; $knownIndex <= $index; $knownIndex++) {
                    $this->knownDirectories[$directories[$knownIndex]] = true;
                }

                break;
            }
        }

        for ($index = $deepestExistingIndex + 1; $index < count($directories); $index++) {
            $directory = $directories[$index];

            [$status] = ($this->request)(
                'MKCOL',
                $this->buildUrl($directory),
                $this->authHeaders(),
                '',
            );

            if (in_array($status, [200, 201, 204, 301, 302, 405], true)) {
                $this->knownDirectories[$directory] = true;

                continue;
            }

            if (in_array($status, [400, 409], true) && $this->exists($directory)) {
                $this->knownDirectories[$directory] = true;

                continue;
            }

            throw new \RuntimeException('WebDAV MKCOL failed with HTTP status ' . $status);
        }
    }

}