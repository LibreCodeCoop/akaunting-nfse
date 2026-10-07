<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\WebDavClient;
use Modules\Nfse\Tests\TestCase;

final class WebDavClientTest extends TestCase
{
    public function testPutSendsBasicAuthAndReturnsWithoutErrorOn2xx(): void
    {
        $captured = [];

        $client = new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            username: 'alice',
            password: 'secret',
            request: static function (string $method, string $url, array $headers, string $body) use (&$captured): array {
                $captured = [
                    'method' => $method,
                    'url' => $url,
                    'headers' => $headers,
                    'body' => $body,
                ];

                return [201, ''];
            },
        );

        $client->put('nfse/2026/doc.xml', '<xml/>');

        self::assertSame('PUT', $captured['method'] ?? null);
        self::assertSame('https://dav.example.com/root/nfse/2026/doc.xml', $captured['url'] ?? null);
        self::assertSame('<xml/>', $captured['body'] ?? null);
        self::assertSame('Basic ' . base64_encode('alice:secret'), $captured['headers']['Authorization'] ?? null);
    }

    public function testPutThrowsRuntimeExceptionWhenResponseIsNotSuccessful(): void
    {
        $client = new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            request: static function (string $method, string $url, array $headers, string $body): array {
                return [500, 'upstream error'];
            },
        );

        $this->expectException(\RuntimeException::class);

        $client->put('nfse/2026/doc.xml', '<xml/>');
    }

    public function testPutCreatesOnlyMissingDirectoriesAfterConflict(): void
    {
        $calls = [];
        $putAttempts = 0;

        $client = new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            request: static function (string $method, string $url, array $headers, string $body) use (&$calls, &$putAttempts): array {
                $calls[] = [$method, $url];

                if ($method === 'PUT') {
                    $putAttempts++;

                    return $putAttempts === 1 ? [409, 'missing parent'] : [201, ''];
                }

                if ($method === 'HEAD') {
                    return str_ends_with($url, '/nfse') ? [200, ''] : [404, ''];
                }

                if ($method === 'MKCOL') {
                    return [201, ''];
                }

                return [500, 'unsupported'];
            },
        );

        $client->put('nfse/2026/04/doc.xml', '<xml/>');

        self::assertSame([
            ['PUT', 'https://dav.example.com/root/nfse/2026/04/doc.xml'],
            ['HEAD', 'https://dav.example.com/root/nfse/2026/04'],
            ['HEAD', 'https://dav.example.com/root/nfse/2026'],
            ['HEAD', 'https://dav.example.com/root/nfse'],
            ['MKCOL', 'https://dav.example.com/root/nfse/2026'],
            ['MKCOL', 'https://dav.example.com/root/nfse/2026/04'],
            ['PUT', 'https://dav.example.com/root/nfse/2026/04/doc.xml'],
        ], $calls);
    }

    public function testPutSkipsDirectoryDiscoveryWhenParentAlreadyExists(): void
    {
        $calls = [];

        $client = new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            request: static function (string $method, string $url, array $headers, string $body) use (&$calls): array {
                $calls[] = [$method, $url];

                return [201, ''];
            },
        );

        $client->put('nfse/2026/doc.xml', '<xml/>');

        self::assertSame([
            ['PUT', 'https://dav.example.com/root/nfse/2026/doc.xml'],
        ], $calls);
    }

    public function testPutEncodesPathSegmentsWithSpaces(): void
    {
        $capturedUrl = null;

        $client = new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            request: static function (string $method, string $url, array $headers, string $body) use (&$capturedUrl): array {
                if ($method === 'MKCOL') {
                    return [201, ''];
                }

                if ($method === 'PUT') {
                    $capturedUrl = $url;

                    return [201, ''];
                }

                return [500, 'unsupported'];
            },
        );

        $client->put('nfse/2026/04 - abril/doc final.xml', '<xml/>');

        self::assertSame('https://dav.example.com/root/nfse/2026/04%20-%20abril/doc%20final.xml', $capturedUrl);
    }

    public function testPutUsesSingleRequestForExistingParentDirectories(): void
    {
        $calls = [];

        $client = new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            request: static function (string $method, string $url, array $headers, string $body) use (&$calls): array {
                $calls[] = [$method, $url];

                return [201, ''];
            },
        );

        $client->put('nfse/2026/04/doc.xml', '<xml/>');
        $client->put('nfse/2026/04/doc.pdf', '%PDF-1.4');

        self::assertSame([
            ['PUT', 'https://dav.example.com/root/nfse/2026/04/doc.xml'],
            ['PUT', 'https://dav.example.com/root/nfse/2026/04/doc.pdf'],
        ], $calls);
    }

    public function testLaravelHttpTimeoutsAreExplicitAndBounded(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/Support/WebDavClient.php');

        self::assertStringContainsString(
            '$timeoutSeconds = (int) ceil($this->timeoutSeconds);',
            $content,
        );
        self::assertStringContainsString(
            '$connectTimeoutSeconds = min(15, $timeoutSeconds);',
            $content,
        );
        self::assertStringContainsString(
            '->connectTimeout($connectTimeoutSeconds)',
            $content,
        );
        self::assertStringContainsString(
            '->timeout($timeoutSeconds)',
            $content,
        );
    }

    public function testDefaultTimeoutAllowsNormalWebDavLatency(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/Support/WebDavClient.php');

        self::assertStringContainsString(
            'private readonly float $timeoutSeconds = 20.0',
            $content,
        );
    }

    public function testRejectsNonPositiveTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            timeoutSeconds: 0,
        );
    }

    public function testExistsReturnsFalseWhenResourceIsNotFound(): void
    {
        $client = new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            request: static function (string $method, string $url, array $headers, string $body): array {
                return [404, ''];
            },
        );

        self::assertFalse($client->exists('nfse/2026/doc.xml'));
    }

    public function testGetReturnsBodyOnSuccessfulResponse(): void
    {
        $captured = [];

        $client = new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            username: 'alice',
            password: 'secret',
            request: static function (string $method, string $url, array $headers, string $body) use (&$captured): array {
                $captured = ['method' => $method, 'url' => $url, 'headers' => $headers];

                return [200, 'PDF_BINARY_CONTENT'];
            },
        );

        $result = $client->get('nfse/2026/doc.pdf');

        self::assertSame('GET', $captured['method'] ?? null);
        self::assertSame('https://dav.example.com/root/nfse/2026/doc.pdf', $captured['url'] ?? null);
        self::assertSame('Basic ' . base64_encode('alice:secret'), $captured['headers']['Authorization'] ?? null);
        self::assertSame('PDF_BINARY_CONTENT', $result);
    }

    public function testGetThrowsRuntimeExceptionOnNon2xxResponse(): void
    {
        $client = new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            request: static function (string $method, string $url, array $headers, string $body): array {
                return [404, 'not found'];
            },
        );

        $this->expectException(\RuntimeException::class);

        $client->get('nfse/2026/missing.pdf');
    }
}
