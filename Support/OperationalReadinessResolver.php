<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Application\EmissionReadiness;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface;

final class OperationalReadinessResolver
{
    /**
     * @param array<string, mixed> $settings
     * @return array{checklist:array<string,bool>,isReady:bool}
     */
    public function evaluate(
        array $settings,
        string $serviceCode,
        SecretStoreInterface $secretStore,
        ?string $certificatePath = null,
        ?int $now = null,
    ): array {
        $cnpj = trim((string) ($settings['cnpj_prestador'] ?? ''));
        $certificatePath ??= $cnpj !== ''
            ? storage_path('app/nfse/pfx/' . $cnpj . '.pfx')
            : '';

        return (new EmissionReadiness())->evaluate(
            settings: $settings,
            hasLocalCertificate: $certificatePath !== '' && is_file($certificatePath),
            hasCertificateSecret: $this->hasCertificateSecret($secretStore, $cnpj),
            serviceCode: $serviceCode,
            now: $now,
        );
    }

    private function hasCertificateSecret(SecretStoreInterface $secretStore, string $cnpj): bool
    {
        if ($cnpj === '') {
            return false;
        }

        try {
            $secret = $secretStore->get('pfx/' . $cnpj);
        } catch (\Throwable) {
            return false;
        }

        return trim((string) ($secret['password'] ?? '')) !== ''
            && trim((string) ($secret['pfx_path'] ?? '')) !== '';
    }
}
