<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\SubstitutionData;

final class SubstitutionDpsBuilder
{
    public function build(
        DpsData $baseDps,
        NfseReceipt $originalReceipt,
        string $reasonCode,
        string $reasonDescription = '',
    ): DpsData {
        $accessKey = trim((string) $originalReceipt->chave_acesso);

        if (preg_match('/^\d{50}$/', $accessKey) !== 1) {
            throw new \InvalidArgumentException(
                'Original NFS-e access key must contain exactly 50 digits.',
            );
        }

        $payload = get_object_vars($baseDps);
        $payload['numeroDps'] = (new SubstitutionDpsNumber())->forOriginalReceipt((int) $originalReceipt->id);
        $payload['substituicao'] = new SubstitutionData(
            chaveNfseSubstituida: $accessKey,
            codigoMotivo: $reasonCode,
            descricaoMotivo: $reasonDescription,
        );

        return (new RuntimeDpsFactory())->make($payload, ['substituicao']);
    }
}
