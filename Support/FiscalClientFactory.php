<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\AdnEnvironmentConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\CertConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\EnvironmentConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\MunicipalParametersConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\HttpTransportInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\AdnClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\MunicipalParametersClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\NfseClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore;

final class FiscalClientFactory
{
    /** @var \Closure(): SecretStoreInterface */
    private readonly \Closure $secretStoreFactory;

    /** @var \Closure(): string */
    private readonly \Closure $cnpjResolver;

    /** @var \Closure(string): string */
    private readonly \Closure $pfxPathResolver;

    /**
     * @param (\Closure(): SecretStoreInterface)|null $secretStoreFactory
     * @param (\Closure(): string)|null $cnpjResolver
     * @param (\Closure(string): string)|null $pfxPathResolver
     */
    public function __construct(
        private readonly HttpTransportInterface $transport,
        private readonly NfseRuntimeContextFactory $runtimeContextFactory = new NfseRuntimeContextFactory(),
        ?\Closure $secretStoreFactory = null,
        ?\Closure $cnpjResolver = null,
        ?\Closure $pfxPathResolver = null,
    ) {
        $this->secretStoreFactory = $secretStoreFactory ?? static function (): SecretStoreInterface {
            $config = VaultConfig::secretStoreConfig();

            return new OpenBaoSecretStore(
                addr: $config['addr'],
                mount: $config['mount'],
                token: $config['token'],
                roleId: $config['roleId'],
                secretId: $config['secretId'],
            );
        };

        $this->cnpjResolver = $cnpjResolver ?? static fn (): string => trim((string) setting('nfse.cnpj_prestador', ''));
        $this->pfxPathResolver = $pfxPathResolver
            ?? static fn (string $cnpj): string => storage_path('app/nfse/pfx/' . $cnpj . '.pfx');
    }

    public function nfse(bool $sandboxMode): FiscalClientContext
    {
        $context = $this->runtimeContext();

        try {
            $client = new NfseClient(
                environment: new EnvironmentConfig(sandboxMode: $sandboxMode),
                cert: $context->cert,
                secretStore: $context->secretStore,
                transport: $this->transport,
            );
        } catch (\Throwable $e) {
            ($context->cleanup)();

            throw $e;
        }

        return new FiscalClientContext($client, $context->cleanup);
    }

    public function adn(bool $sandboxMode): FiscalClientContext
    {
        $context = $this->runtimeContext();

        try {
            $client = new AdnClient(
                environment: new AdnEnvironmentConfig(sandboxMode: $sandboxMode),
                cert: $context->cert,
                transport: $this->transport,
            );
        } catch (\Throwable $e) {
            ($context->cleanup)();

            throw $e;
        }

        return new FiscalClientContext($client, $context->cleanup);
    }

    public function municipalParameters(bool $sandboxMode): FiscalClientContext
    {
        $context = $this->runtimeContext();

        try {
            $client = new MunicipalParametersClient(
                config: new MunicipalParametersConfig(sandboxMode: $sandboxMode),
                cert: $context->cert,
                transport: $this->transport,
            );
        } catch (\Throwable $e) {
            ($context->cleanup)();

            throw $e;
        }

        return new FiscalClientContext($client, $context->cleanup);
    }

    private function runtimeContext(): NfseRuntimeContext
    {
        $cnpj = ($this->cnpjResolver)();

        if ($cnpj === '') {
            throw new \RuntimeException('Service provider CNPJ is not configured.');
        }

        $secretStore = ($this->secretStoreFactory)();
        $cert = new CertConfig(
            cnpj: $cnpj,
            pfxPath: ($this->pfxPathResolver)($cnpj),
            vaultPath: 'pfx/' . $cnpj,
        );

        return $this->runtimeContextFactory->create($cert, $secretStore);
    }
}
