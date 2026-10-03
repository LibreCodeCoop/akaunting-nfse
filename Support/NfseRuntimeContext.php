<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Config\CertConfig;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;

final readonly class NfseRuntimeContext
{
    /**
     * @param \Closure(): void $cleanup
     */
    public function __construct(
        public CertConfig $cert,
        public SecretStoreInterface $secretStore,
        public \Closure $cleanup,
    ) {
    }
}
