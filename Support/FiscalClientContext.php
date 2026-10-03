<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\AdnClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\MunicipalParametersClient;

final class FiscalClientContext
{
    private bool $closed = false;

    /**
     * @param \Closure(): void $cleanup
     */
    public function __construct(
        private readonly NfseClientInterface|AdnClient|MunicipalParametersClient $client,
        private readonly \Closure $cleanup,
    ) {
    }

    public function nfseClient(): NfseClientInterface
    {
        if (!$this->client instanceof NfseClientInterface) {
            throw new \LogicException('Fiscal client context does not contain a SEFIN NFS-e client.');
        }

        return $this->client;
    }

    public function adnClient(): AdnClient
    {
        if (!$this->client instanceof AdnClient) {
            throw new \LogicException('Fiscal client context does not contain an ADN client.');
        }

        return $this->client;
    }

    public function municipalParametersClient(): MunicipalParametersClient
    {
        if (!$this->client instanceof MunicipalParametersClient) {
            throw new \LogicException('Fiscal client context does not contain a municipal parameters client.');
        }

        return $this->client;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        ($this->cleanup)();
        $this->closed = true;
    }

    public function __destruct()
    {
        $this->close();
    }
}
