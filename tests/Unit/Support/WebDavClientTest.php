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

    public function testPutCreatesNestedDirectoriesBeforeUploadingFile(): void
    {
        $calls = [];

        $client = new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            request: static function (string $method, string $url, array $headers, string $body) use (&$calls): array {
                $calls[] = [$method, $url];

                if ($method === 'MKCOL') {
                    return [201, ''];
                }

                if ($method === 'PUT') {
                    return [201, ''];
                }

                return [500, 'unsupported'];
            },
        );

        $client->put('nfse/2026/04/doc.xml', '<xml/>');

        self::assertSame([
            ['MKCOL', 'https://dav.example.com/root/nfse'],
            ['MKCOL', 'https://dav.example.com/root/nfse/2026'],
            ['MKCOL', 'https://dav.example.com/root/nfse/2026/04'],
            ['PUT', 'https://dav.example.com/root/nfse/2026/04/doc.xml'],
        ], $calls);
    }

    public function testPutTreatsMkcol400AsExistingDirectoryWhenHeadSucceeds(): void
    {
        $calls = [];

        $client = new WebDavClient(
            baseUrl: 'https://dav.example.com/root',
            request: static function (string $method, string $url, array $headers, string $body) use (&$calls): array {
                $calls[] = [$method, $url];

                if ($method === 'MKCOL') {
                    return [400, 'already exists'];
                }

                if ($method === 'HEAD') {
                    return [200, ''];
                }

                if ($method === 'PUT') {
                    return [201, ''];
                }

                return [500, 'unsupported'];
            },
        );

        $client->put('nfse/2026/doc.xml', '<xml/>');

        self::assertSame([
            ['MKCOL', 'https://dav.example.com/root/nfse'],
            ['HEAD', 'https://dav.example.com/root/nfse'],
            ['MKCOL', 'https://dav.example.com/root/nfse/2026'],
            ['HEAD', 'https://dav.example.com/root/nfse/2026'],
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
