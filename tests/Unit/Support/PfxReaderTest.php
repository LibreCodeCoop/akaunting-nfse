<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\PfxReader;
use Modules\Nfse\Tests\TestCase;

class PfxReaderTest extends TestCase
{
    private const PASSWORD = 'test-password';

    private static string $pfx;
    private static string $pem;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        [self::$pfx, self::$pem] = self::makePfxAndPem([
            'countryName'      => 'BR',
            'organizationName' => 'EMPRESA TESTE LTDA',
            'commonName'       => 'EMPRESA TESTE',
        ]);
    }

    public function testReadsCertificatePemWithNativePhpReader(): void
    {
        $reader = new PfxReader(
            nativeReader: static fn (): ?string => self::$pem,
            legacyReader: static fn (): ?string => null,
        );

        $pem = $reader->extractCertificatePem(self::$pfx, self::PASSWORD);

        self::assertStringContainsString('BEGIN CERTIFICATE', $pem);
    }

    public function testFallsBackToLegacyReaderWhenNativeFails(): void
    {
        $reader = new PfxReader(
            nativeReader: static fn (): ?string => null,
            legacyReader: static fn (): ?string => self::$pem,
        );

        $pem = $reader->extractCertificatePem(self::$pfx, self::PASSWORD);

        self::assertStringContainsString('BEGIN CERTIFICATE', $pem);
    }

    public function testThrowsWhenBothReadersFail(): void
    {
        $reader = new PfxReader(
            nativeReader: static fn (): ?string => null,
            legacyReader: static fn (): ?string => null,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid PFX content or wrong password.');

        $reader->extractCertificatePem(self::$pfx, self::PASSWORD);
    }

    /**
     * @param array<string, string> $subject
     * @return array{string, string}
     */
    private static function makePfxAndPem(array $subject): array
    {
        $pkey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        assert($pkey !== false, 'openssl_pkey_new() failed');

        $csr = openssl_csr_new($subject, $pkey, ['digest_alg' => 'sha256']);
        assert($csr !== false, 'openssl_csr_new() failed');

        $x509 = openssl_csr_sign($csr, null, $pkey, 1, ['digest_alg' => 'sha256']);
        assert($x509 !== false, 'openssl_csr_sign() failed');

        $pkcs12 = '';
        $exportedPfx = openssl_pkcs12_export($x509, $pkcs12, $pkey, self::PASSWORD, ['extracerts' => []]);
        assert($exportedPfx, 'openssl_pkcs12_export() failed');

        $pem = '';
        $exportedPem = openssl_x509_export($x509, $pem);
        assert($exportedPem, 'openssl_x509_export() failed');

        return [$pkcs12, $pem];
    }
}
