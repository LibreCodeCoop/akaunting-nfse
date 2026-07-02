<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use LibreCodeCoop\NfsePHP\Config\CertConfig;
use LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use LibreCodeCoop\NfsePHP\Exception\PfxImportException;
use LibreCodeCoop\NfsePHP\Exception\SecretStoreException;
use Modules\Nfse\Support\TransportCertificateManager;
use Modules\Nfse\Tests\TestCase;

final class TransportCertificateManagerTest extends TestCase
{
    private const CNPJ = '12345678000195';
    private const PASSWORD = 'test-password';

    private string $storageRoot;
    private string $pfxPath;

    private static string $pfx;
    private static string $certificatePem;
    private static string $privateKeyPem;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        [self::$pfx, self::$certificatePem, self::$privateKeyPem] = self::makePfxMaterial();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . '/nfse-transport-manager-' . uniqid('', true);

        if (!is_dir($this->storageRoot)) {
            mkdir($this->storageRoot, 0o777, true);
        }

        $this->pfxPath = $this->storageRoot . '/source.pfx';
        file_put_contents($this->pfxPath, self::$pfx);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);

        parent::tearDown();
    }

    public function testEnsureGeneratesTransportArtifactsFromPfxAndReturnsPredictablePaths(): void
    {
        $manager = $this->makeManager();

        [$certificatePath, $privateKeyPath, $cleanup] = $manager->prepare(
            $this->makeCertConfig(),
            $this->makeSecretStore(['password' => self::PASSWORD]),
        );

        self::assertStringStartsWith($this->storageRoot . '/', $certificatePath);
        self::assertStringStartsWith($this->storageRoot . '/', $privateKeyPath);
        self::assertFileExists($certificatePath);
        self::assertFileExists($privateKeyPath);
        self::assertSame(self::$certificatePem, trim((string) file_get_contents($certificatePath)));
        self::assertSame(self::$privateKeyPem, trim((string) file_get_contents($privateKeyPath)));
        self::assertSame('0600', substr(sprintf('%o', fileperms($certificatePath)), -4));
        self::assertSame('0600', substr(sprintf('%o', fileperms($privateKeyPath)), -4));
        $this->assertPemPairIsValid($certificatePath, $privateKeyPath);

        $cleanup();

        self::assertFileDoesNotExist($certificatePath);
        self::assertFileDoesNotExist($privateKeyPath);
    }

    public function testPrepareCreatesFreshTemporaryArtifactsForEachInvocation(): void
    {
        $manager = $this->makeManager();

        [$certificatePath, $privateKeyPath, $cleanup] = $manager->prepare(
            $this->makeCertConfig(),
            $this->makeSecretStore(['password' => self::PASSWORD]),
        );

        [$certificatePathAgain, $privateKeyPathAgain, $cleanupAgain] = $manager->prepare(
            $this->makeCertConfig(),
            $this->makeSecretStore(['password' => self::PASSWORD]),
        );

        self::assertNotSame($certificatePath, $certificatePathAgain);
        self::assertNotSame($privateKeyPath, $privateKeyPathAgain);
        $this->assertPemPairIsValid($certificatePathAgain, $privateKeyPathAgain);

        $cleanup();
        $cleanupAgain();
    }

    public function testCleanupRemovesTemporaryArtifactsIdempotently(): void
    {
        $manager = $this->makeManager();

        [$certificatePath, $privateKeyPath, $cleanup] = $manager->prepare(
            $this->makeCertConfig(),
            $this->makeSecretStore(['password' => self::PASSWORD]),
        );

        $cleanup();
        $cleanup();

        self::assertFileDoesNotExist($certificatePath);
        self::assertFileDoesNotExist($privateKeyPath);
    }

    /**
     * @dataProvider missingOrInvalidPasswordProvider
     */
    public function testEnsureThrowsExplicitExceptionWhenPasswordIsMissingOrInvalid(array $secret, string $expectedException, string $expectedMessageFragment): void
    {
        $manager = $this->makeManager();

        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedMessageFragment);

        $manager->prepare($this->makeCertConfig(), $this->makeSecretStore($secret));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: class-string<\Throwable>, 2: string}>
     */
    public static function missingOrInvalidPasswordProvider(): array
    {
        return [
            'missing password' => [
                [],
                SecretStoreException::class,
                'Missing PFX password in OpenBao secret',
            ],
            'invalid password' => [
                ['password' => 'wrong-password'],
                PfxImportException::class,
                'Failed to import PFX for CNPJ ' . self::CNPJ,
            ],
        ];
    }

    public function testEnsureThrowsExplicitExceptionWhenPfxIsMissing(): void
    {
        unlink($this->pfxPath);

        $manager = $this->makeManager();

        $this->expectException(PfxImportException::class);
        $this->expectExceptionMessage('PFX file not found for CNPJ ' . self::CNPJ);

        $manager->prepare(
            $this->makeCertConfig(),
            $this->makeSecretStore(['password' => self::PASSWORD]),
        );
    }

    private function makeManager(): TransportCertificateManager
    {
        return new TransportCertificateManager(
            temporaryDirectoryResolver: fn (): string => $this->storageRoot,
        );
    }

    private function makeCertConfig(): CertConfig
    {
        return new CertConfig(
            cnpj: self::CNPJ,
            pfxPath: $this->pfxPath,
            vaultPath: 'pfx/' . self::CNPJ,
        );
    }

    /**
     * @param array<string, string> $secret
     */
    private function makeSecretStore(array $secret): SecretStoreInterface
    {
        return new class ($secret) implements SecretStoreInterface {
            /** @param array<string, string> $secret */
            public function __construct(private readonly array $secret)
            {
            }

            public function get(string $path): array
            {
                return $this->secret;
            }

            public function put(string $path, array $data): void
            {
            }

            public function delete(string $path): void
            {
            }
        };
    }

    private function assertPemPairIsValid(string $certificatePath, string $privateKeyPath): void
    {
        $certificate = openssl_x509_read((string) file_get_contents($certificatePath));
        $privateKey = openssl_pkey_get_private((string) file_get_contents($privateKeyPath));

        self::assertNotFalse($certificate);
        self::assertNotFalse($privateKey);
        self::assertTrue(openssl_x509_check_private_key($certificate, $privateKey));
    }

    /**
     * @return array{string, string, string}
     */
    private static function makePfxMaterial(): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        assert($privateKey !== false, 'openssl_pkey_new() failed');

        $csr = openssl_csr_new([
            'countryName' => 'BR',
            'organizationName' => 'EMPRESA TESTE LTDA',
            'commonName' => 'EMPRESA TESTE',
        ], $privateKey, ['digest_alg' => 'sha256']);
        assert($csr !== false, 'openssl_csr_new() failed');

        $certificate = openssl_csr_sign($csr, null, $privateKey, 1, ['digest_alg' => 'sha256']);
        assert($certificate !== false, 'openssl_csr_sign() failed');

        $pfx = '';
        $certificatePem = '';
        $privateKeyPem = '';

        assert(openssl_pkcs12_export($certificate, $pfx, $privateKey, self::PASSWORD), 'openssl_pkcs12_export() failed');
        assert(openssl_x509_export($certificate, $certificatePem), 'openssl_x509_export() failed');
        assert(openssl_pkey_export($privateKey, $privateKeyPem), 'openssl_pkey_export() failed');

        return [$pfx, trim($certificatePem), trim($privateKeyPem)];
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
