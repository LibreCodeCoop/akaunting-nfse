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
}
