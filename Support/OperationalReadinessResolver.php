<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Application\EmissionReadiness;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

final class OperationalReadinessResolver
{
    /**
     * @param array<string, mixed> $settings
     * @return array{checklist:array<string,bool>,isReady:bool}
     */
    public function evaluate(
        array $settings,
        string $serviceCode,
        bool $hasCertificateSecret,
        string $certificatePath,
        ?int $now = null,
        ?bool $runtimeContractAvailable = null,
    ): array {
        $runtimeContractAvailable ??= interface_exists(NfseClientInterface::class)
            && class_exists(DpsData::class)
            && class_exists(ReceiptData::class);

        return (new EmissionReadiness())->evaluate(
            settings: $settings,
            hasLocalCertificate: $certificatePath !== '' && is_file($certificatePath),
            hasCertificateSecret: $hasCertificateSecret,
            serviceCode: $serviceCode,
            now: $now,
            runtimeContractAvailable: $runtimeContractAvailable,
        );
    }
}
