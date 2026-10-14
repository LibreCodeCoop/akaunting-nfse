<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Support\ItemMunicipalCompanyContext;
use Modules\Nfse\Support\NfseCompanyCertificatePath;
use Tests\Feature\FeatureTestCase;

final class ItemMunicipalCompanyContextTest extends FeatureTestCase
{
    public function testMunicipalContextUsesOwnerProfileNotAmbientLegacySettings(): void
    {
        $this->loginAs();
        $ownerId = (int) $this->company->id;
        setting()->set([
            'nfse.municipio_ibge' => '9999999',
            'nfse.sandbox_mode' => '0',
        ]);
        setting()->save();

        $context = new ItemMunicipalCompanyContext();
        self::assertSame(
            ['municipio_ibge' => '', 'sandbox_mode' => true],
            $context->forCompany($ownerId),
        );
        self::assertSame(
            ['municipio_ibge' => '', 'sandbox_mode' => true],
            $context->forCompany(0),
        );

        $otherId = DB::table('companies')->insertGetId([
            'domain' => 'municipal-items-second.example.test',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            [$ownerId, '3304557', '1'],
            [$otherId, '4106902', '0'],
        ] as [$companyId, $municipality, $sandbox]) {
            NfseCompanyProfile::query()->create([
                'company_id' => $companyId,
                'status' => 'active',
                'certificate_ref' => NfseCompanyCertificatePath::newReference(),
                'certificate_cnpj' => '11222333000181',
                'certificate_valid_from' => time() - 3600,
                'certificate_valid_to' => time() + 3600,
                'fiscal_parameters' => [
                    'municipio_ibge' => $municipality,
                    'sandbox_mode' => $sandbox,
                ],
            ]);
        }

        self::assertSame(
            ['municipio_ibge' => '3304557', 'sandbox_mode' => true],
            $context->forCompany($ownerId),
        );
        self::assertSame(
            ['municipio_ibge' => '4106902', 'sandbox_mode' => false],
            $context->forCompany($otherId),
        );
    }
}
