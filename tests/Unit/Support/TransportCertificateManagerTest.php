<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\TransportCertificateManager;
use Modules\Nfse\Tests\TestCase;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\CertConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\PfxImportException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\SecretStoreException;

final class TransportCertificateManagerTest extends TestCase
{
    private const CNPJ = '12345678000195';
    private const PASSWORD = 'test-password';
    private const PFX_CONTENT = 'synthetic-pfx-binary';
    private const CERTIFICATE_PEM = <<<'PEM'
        -----BEGIN CERTIFICATE-----
        MIIBszCCAVmgAwIBAgIUQnludGhldGljLWNlcnRpZmljYXRlMB4XDTI2MDcwMTAwMDAw
        MFoXDTM2MDYyODAwMDAwMFowGDEWMBQGA1UEAwwNU1lOVEhFVElDIFRFU1QwXDANBgkq
        hkiG9w0BAQEFAANLADBIAkEA0M9aR1jQ7f2sQjLJ8n+6g9iQ+g4kJjJ5jJm6Q2R0d0QK
        L2F4bS95bHhYVjZyRjBvVnN3TjM3V1ZyR0xzQ2pJcUlTQwIDAQABo1MwUTAdBgNVHQ4E
        FgQUU3ludGhldGljLWNlcnQtZmluZ2VycHJpbnQwHwYDVR0jBBgwFoAUU3ludGhldGlj
        LWNlcnQtZmluZ2VycHJpbnQwDwYDVR0TAQH/BAUwAwEB/zANBgkqhkiG9w0BAQsFAANB
        ABEiM0RVZneImaq7zN3u/wARIjNEVWZ3iJmqu8zd7v8AESIzRFVmd4iZqrvM3e7/ABEi
        M0RVZneImaq7zN3u/wA=
        -----END CERTIFICATE-----
        PEM;
    private const PRIVATE_KEY_PEM = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDQl5GQX3N5bnRoZXRp
        Yy1wcml2YXRlLWtleS1maXh0dXJlLXBheWxvYWQtZm9yLXRlc3Rpbmctb25seS0xMjM0
        NTY3ODkwYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXo4NzY1NDMyMTAwMDAwMDAwMDAw
        MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAw
        MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAw
        MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAw
        AgMBAAECggEAAwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
        AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
        AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
        AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
        AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
        AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
        AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==
        -----END PRIVATE KEY-----
        PEM;

    private string $storageRoot;
    private string $pfxPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . '/nfse-transport-manager-' . uniqid('', true);

        if (!is_dir($this->storageRoot)) {
            mkdir($this->storageRoot, 0o777, true);
        }

        $this->pfxPath = $this->storageRoot . '/source.pfx';
        file_put_contents($this->pfxPath, self::PFX_CONTENT);
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
        self::assertSame(trim(self::CERTIFICATE_PEM), trim((string) file_get_contents($certificatePath)));
        self::assertSame(trim(self::PRIVATE_KEY_PEM), trim((string) file_get_contents($privateKeyPath)));
        self::assertSame('0600', substr(sprintf('%o', fileperms($certificatePath)), -4));
        self::assertSame('0600', substr(sprintf('%o', fileperms($privateKeyPath)), -4));

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
            fn (): string => $this->storageRoot,
            function (string $pfxContent, string $password, string $cnpj): array {
                self::assertSame(self::PFX_CONTENT, $pfxContent);
                self::assertSame(self::CNPJ, $cnpj);

                if ($password !== self::PASSWORD) {
                    throw new PfxImportException('Failed to import PFX for CNPJ ' . self::CNPJ . ': synthetic invalid password');
                }

                return [trim(self::PRIVATE_KEY_PEM), trim(self::CERTIFICATE_PEM)];
            },
            function (string $certificatePem, string $privateKeyPem, string $cnpj): void {
                self::assertSame(trim(self::CERTIFICATE_PEM), $certificatePem);
                self::assertSame(trim(self::PRIVATE_KEY_PEM), $privateKeyPem);
                self::assertSame(self::CNPJ, $cnpj);
            },
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
