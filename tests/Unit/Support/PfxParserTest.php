<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\PfxParser;
use Modules\Nfse\Tests\TestCase;

class PfxParserTest extends TestCase
{
    private const PASSWORD = 'test-password';
    private const TEST_CNPJ = '12345678000195';

    private static string $pfxWithCnpjInCn;
    private static string $pfxWithCnpjInSerial;
    private static string $pfxNoCnpj;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // ICP-Brasil format: CNPJ embedded in CN as "NAME:CNPJ14DIGITS"
        self::$pfxWithCnpjInCn = self::makePfx(
            [
                'countryName'      => 'BR',
                'organizationName' => 'EMPRESA TESTE LTDA',
                'commonName'       => 'EMPRESA TESTE LTDA:' . self::TEST_CNPJ,
            ],
            self::PASSWORD,
        );

        // CNPJ only in serialNumber field (another common ICP-Brasil variation)
        self::$pfxWithCnpjInSerial = self::makePfx(
            [
                'countryName'    => 'BR',
                'organizationName' => 'EMPRESA TESTE LTDA',
                'commonName'     => 'EMPRESA TESTE',
                'serialNumber'   => self::TEST_CNPJ,
            ],
            self::PASSWORD,
        );

        // Regular certificate without any CNPJ
        self::$pfxNoCnpj = self::makePfx(
            [
                'countryName'      => 'BR',
                'organizationName' => 'Test Only',
                'commonName'       => 'TEST-NFSE',
            ],
            self::PASSWORD,
        );
    }

    public function testExtractsCnpjFromCnField(): void
    {
        $result = PfxParser::extractFromContent(self::$pfxWithCnpjInCn, self::PASSWORD);

        self::assertSame(self::TEST_CNPJ, $result['cnpj']);
    }

    public function testExtractsCnpjFromSerialNumberField(): void
    {
        $result = PfxParser::extractFromContent(self::$pfxWithCnpjInSerial, self::PASSWORD);

        self::assertSame(self::TEST_CNPJ, $result['cnpj']);
    }

    public function testReturnsNullCnpjWhenNotPresent(): void
    {
        $result = PfxParser::extractFromContent(self::$pfxNoCnpj, self::PASSWORD);

        self::assertNull($result['cnpj']);
    }

    public function testThrowsOnWrongPassword(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid PFX content or wrong password.');

        PfxParser::extractFromContent(self::$pfxWithCnpjInCn, 'wrong-password');
    }

    public function testThrowsOnInvalidContent(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid PFX content or wrong password.');

        PfxParser::extractFromContent('not-a-pfx-file', self::PASSWORD);
    }

    public function testResultAlwaysHasCnpjKey(): void
    {
        $result = PfxParser::extractFromContent(self::$pfxNoCnpj, self::PASSWORD);

        self::assertArrayHasKey('cnpj', $result);
    }

    // -----------------------------------------------------------------------

    private static function makePfx(array $subject, string $password): string
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
        $exported = openssl_pkcs12_export($x509, $pkcs12, $pkey, $password, ['extracerts' => []]);
        assert($exported, 'openssl_pkcs12_export() failed');

        return $pkcs12;
    }
}
