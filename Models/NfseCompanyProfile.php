<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Nfse\Support\NfseCompanyCertificatePath;

/**
 * One backend NFS-e configuration for an existing Akaunting company.
 * Not a substitute for its native company registration or its fiscal history.
 *
 * @property int $company_id
 * @property string $status
 * @property string|null $certificate_ref
 * @property string|null $staged_certificate_ref
 * @property string|null $certificate_cnpj
 * @property string|null $certificate_fingerprint_sha256
 * @property int|null $certificate_valid_from
 * @property int|null $certificate_valid_to
 * @property array<string,mixed>|null $fiscal_parameters
 */
final class NfseCompanyProfile extends Model
{
    protected $table = 'nfse_company_profiles';

    protected $fillable = [
        'company_id',
        'status',
        'certificate_ref',
        'staged_certificate_ref',
        'certificate_cnpj',
        'certificate_fingerprint_sha256',
        'certificate_valid_from',
        'certificate_valid_to',
        'fiscal_parameters',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'certificate_valid_from' => 'integer',
        'certificate_valid_to' => 'integer',
        'fiscal_parameters' => 'array',
    ];

    public function certificateVaultPath(): ?string
    {
        $ref = $this->certificate_ref;

        return is_string($ref) && $ref !== ''
            ? NfseCompanyCertificatePath::vaultPath((int) $this->company_id, $ref)
            : null;
    }

    public function certificateFilePath(): ?string
    {
        $ref = $this->certificate_ref;

        return is_string($ref) && $ref !== ''
            ? NfseCompanyCertificatePath::filePath((int) $this->company_id, $ref)
            : null;
    }

    /**
     * Only non-sensitive metadata is exposed to authenticated admin consumers.
     *
     * @return array<string,mixed>
     */
    public function publicData(): array
    {
        return [
            'company_id' => (int) $this->company_id,
            'status' => (string) $this->status,
            'certificate_cnpj' => $this->certificate_cnpj,
            'certificate_fingerprint_sha256' => $this->certificate_fingerprint_sha256,
            'certificate_valid_from' => $this->certificate_valid_from,
            'certificate_valid_to' => $this->certificate_valid_to,
            'fiscal_parameters' => $this->fiscal_parameters ?? [],
        ];
    }
}
