<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\PfxBundleReader;
use Modules\Nfse\Support\PfxInspector;
use Modules\Nfse\Tests\TestCase;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\PfxImportException;

final class PfxInspectorTest extends TestCase
{
    private const PASSWORD = 'test-password';
    private const CNPJ = '12345678000195';

    private string $pfx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pfx = $this->makePfx(days: 365);
    }

    public function testBundleReaderReturnsMatchingCertificateAndPrivateKey(): void
    {
        $bundle = (new PfxBundleReader())->read($this->pfx, self::PASSWORD, self::CNPJ);

        $certificate = openssl_x509_read($bundle['certificate_pem']);
        $privateKey = openssl_pkey_get_private($bundle['private_key_pem']);

        self::assertNotFalse($certificate);
        self::assertNotFalse($privateKey);
        self::assertTrue(openssl_x509_check_private_key($certificate, $privateKey));
    }

    public function testInspectorReturnsCnpjValidityAndFingerprint(): void
    {
        $now = time();
        $inspector = new PfxInspector(clock: static fn (): int => $now);

        $result = $inspector->inspect($this->pfx, self::PASSWORD);

        self::assertSame(self::CNPJ, $result['cnpj']);
        self::assertTrue($result['is_currently_valid']);
        self::assertGreaterThan($now, $result['valid_to']);
        self::assertMatchesRegularExpression('/^[A-F0-9]{64}$/', $result['fingerprint_sha256']);
        self::assertSame(gmdate(DATE_ATOM, $result['valid_to']), $result['valid_to_iso']);
    }

    public function testInspectorReportsCertificateAsExpiredAtFutureClock(): void
    {
        $metadata = (new PfxInspector())->inspect($this->pfx, self::PASSWORD);
        $future = $metadata['valid_to'] + 1;

        $result = (new PfxInspector(clock: static fn (): int => $future))
            ->inspect($this->pfx, self::PASSWORD);

        self::assertFalse($result['is_currently_valid']);
        self::assertLessThan(0, $result['days_until_expiry']);
    }

    public function testBundleReaderRejectsWrongPassword(): void
    {
        $this->expectException(PfxImportException::class);

        (new PfxBundleReader())->read($this->pfx, 'wrong-password', self::CNPJ);
    }

    private function makePfx(int $days): string
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        self::assertNotFalse($privateKey);

        $csr = openssl_csr_new([
            'countryName' => 'BR',
            'organizationName' => 'EMPRESA TESTE LTDA',
            'commonName' => 'EMPRESA TESTE LTDA:' . self::CNPJ,
        ], $privateKey, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, $days, ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate);

        $pfx = '';
        self::assertTrue(openssl_pkcs12_export($certificate, $pfx, $privateKey, self::PASSWORD));

        return $pfx;
    }
}
