<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\CertConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;

final class NfseRuntimeContextFactory
{
    public function __construct(
        private readonly TransportCertificateManager $transportCertificateManager = new TransportCertificateManager(),
    ) {
    }

    public function create(CertConfig $cert, SecretStoreInterface $secretStore): NfseRuntimeContext
    {
        [$certificatePath, $privateKeyPath, $cleanup] = $this->transportCertificateManager->prepare(
            $cert,
            $secretStore,
        );

        return new NfseRuntimeContext(
            cert: new CertConfig(
                cnpj: $cert->cnpj,
                pfxPath: $cert->pfxPath,
                vaultPath: $cert->vaultPath,
                transportCertificatePath: $certificatePath,
                transportPrivateKeyPath: $privateKeyPath,
            ),
            secretStore: $secretStore,
            cleanup: $cleanup,
        );
    }
}
