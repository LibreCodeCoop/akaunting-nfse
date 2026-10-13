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

/**
 * Every fiscal client requires an explicit native Akaunting owner. In
 * particular, neither the selected HTTP company nor the CNPJ can select a
 * certificate. The CNPJ may legitimately coincide across different profiles.
 */
final class FiscalClientFactory
{
    /** @var \Closure(): SecretStoreInterface */
    private readonly \Closure $secretStoreFactory;

    /** @var \Closure(int): array{cnpj:string,pfx_path:string,vault_path:string,sandbox_mode:mixed} */
    private readonly \Closure $profileResolver;

    private readonly bool $injectedProfileResolver;

    /**
     * @param (\Closure(): SecretStoreInterface)|null $secretStoreFactory
     * @param (\Closure(int): array{cnpj:string,pfx_path:string,vault_path:string,sandbox_mode:mixed})|null $profileResolver
     */
    public function __construct(
        private readonly HttpTransportInterface $transport,
        private readonly NfseRuntimeContextFactory $runtimeContextFactory = new NfseRuntimeContextFactory(),
        ?\Closure $secretStoreFactory = null,
        ?\Closure $profileResolver = null,
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

        // The injected resolver is exclusively for isolated tests. In the
        // application, ownership and certificate paths come from #419.
        $this->injectedProfileResolver = $profileResolver !== null;
        $this->profileResolver = $profileResolver ?? static function (int $companyId): array {
            $profile = (new NfseCompanyProfileCatalog())->requireActive($companyId);
            $validUntil = (int) ($profile->certificate_valid_to ?? 0);

            if ($validUntil <= 0 || $validUntil < time()) {
                throw new \DomainException('NFS-e company certificate is expired or has no verified validity.');
            }

            return [
                'cnpj' => (string) ($profile->certificate_cnpj ?? ''),
                'pfx_path' => (string) $profile->certificateFilePath(),
                'vault_path' => (string) $profile->certificateVaultPath(),
                'sandbox_mode' => ($profile->fiscal_parameters ?? [])['sandbox_mode'] ?? true,
            ];
        };
    }

    public function nfse(bool $sandboxMode, int $companyId): FiscalClientContext
    {
        $context = $this->runtimeContext($sandboxMode, $companyId);

        try {
            $client = new NfseClient(
                environment: new EnvironmentConfig(sandboxMode: $sandboxMode),
                cert: $context->cert,
                // The pinned nfse-php DpsSigner still asks for pfx/{cnpj}.
                // Translate only that signer lookup to this explicit owner's
                // immutable Vault revision; never read the old CNPJ key.
                secretStore: new CompanyScopedSignerSecretStore(
                    $context->secretStore,
                    $context->cert->cnpj,
                    $context->cert->vaultPath,
                ),
                transport: $this->transport,
            );
        } catch (\Throwable $e) {
            ($context->cleanup)();

            throw $e;
        }

        return new FiscalClientContext($client, $context->cleanup);
    }

    public function adn(bool $sandboxMode, int $companyId): FiscalClientContext
    {
        $context = $this->runtimeContext($sandboxMode, $companyId);

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

    public function municipalParameters(bool $sandboxMode, int $companyId): FiscalClientContext
    {
        $context = $this->runtimeContext($sandboxMode, $companyId);

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

    private function runtimeContext(bool $sandboxMode, int $companyId): NfseRuntimeContext
    {
        if ($companyId <= 0) {
            throw new \DomainException('Fiscal operation requires an explicit Akaunting company owner.');
        }

        $prepare = function () use ($companyId, $sandboxMode): NfseRuntimeContext {
            $profile = ($this->profileResolver)($companyId);
            $cnpj = trim((string) ($profile['cnpj'] ?? ''));
            $vaultPath = trim((string) ($profile['vault_path'] ?? ''));
            $pfxPath = trim((string) ($profile['pfx_path'] ?? ''));
            $environment = filter_var($profile['sandbox_mode'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($cnpj === '' || $vaultPath === '' || $pfxPath === '' || $environment === null) {
                throw new \DomainException('NFS-e company profile is incomplete.');
            }
            if ($environment !== $sandboxMode) {
                throw new \DomainException('NFS-e operation environment differs from the owning company profile.');
            }

            $secretStore = ($this->secretStoreFactory)();
            $cert = new CertConfig(
                cnpj: $cnpj,
                pfxPath: $pfxPath,
                vaultPath: $vaultPath,
            );

            // Materialize mTLS keys while serialized against certificate
            // rotation/deletion. Later calls use temporary private material.
            return $this->runtimeContextFactory->create($cert, $secretStore);
        };

        if ($this->injectedProfileResolver) {
            return $prepare();
        }

        return (new NfseCompanyProfileOperationLock())->run($companyId, $prepare);
    }
}
