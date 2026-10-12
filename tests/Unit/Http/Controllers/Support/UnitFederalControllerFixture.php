<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Http\Controllers\Support;

use App\Models\Document\Document as Invoice;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Http\Controllers\ControllerIsolationState;
use Modules\Nfse\Http\Controllers\InvoiceController;
use Modules\Nfse\Support\ApproximateTaxSourceResolver;
use Modules\Nfse\Support\InvoiceFederalPayloadResolver;

/**
 * Controller-only HTTP fixtures. No law, name or rate is inferred.
 * Tax calculation and rejection are covered by resolver Unit and Feature.
 */
class UnitFederalControllerFixture extends InvoiceController
{
    protected function invoiceFederalPayloadResolver(): InvoiceFederalPayloadResolver
    {
        return new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed =>
                ControllerIsolationState::$settings[$key] ?? $default,
            taxFactsResolver: static fn (int $company, int $invoice, string $date, ?array $ids): array =>
                array_values(array_filter(
                    ControllerIsolationState::$fiscalTaxFacts,
                    static fn (FiscalTaxFact $fact): bool => $ids === null
                        || in_array($fact->documentItemId, $ids, true),
                )),
            approximateTaxSources: new ApproximateTaxSourceResolver(static fn (): ?array => null),
        );
    }

    protected function federalPayloadValues(
        Invoice $invoice,
        ?array $documentItemIds = null,
        ?float $amountOverride = null,
        mixed $municipalPercentFallback = null,
        string $nationalServiceCode = '',
        string $competenceDate = '',
    ): array {
        // Neutral fiscal DTO in tests focused on HTTP mechanics rather than taxes.
        return [
            'federalPiscofinsSituacaoTributaria' => '',
            'federalPiscofinsTipoRetencao' => '',
            'federalPiscofinsBaseCalculo' => '',
            'federalPiscofinsAliquotaPis' => '',
            'federalPiscofinsValorPis' => '',
            'federalPiscofinsAliquotaCofins' => '',
            'federalPiscofinsValorCofins' => '',
            'federalValorIrrf' => '',
            'federalValorCsll' => '',
            'federalValorCp' => '',
            'indicadorTributacao' => 0,
            'approximateTaxEvidence' => ['status' => 'unknown', 'reason' => 'no_synthetic_source', 'source' => null],
            'totalTributosPercentualFederal' => '',
            'totalTributosPercentualEstadual' => '',
            'totalTributosPercentualMunicipal' => '',
        ];
    }
}
