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
    /** @return array<string,mixed> */
    protected function fiscalParametersForInvoice(Invoice $invoice): array
    {
        return $this->companyFiscalParameters((int) $invoice->company_id);
    }

    /** @return array<string,mixed> */
    protected function companyFiscalParameters(int $companyId): array
    {
        $fiscal = [];
        foreach (ControllerIsolationState::$settings as $key => $value) {
            if (str_starts_with((string) $key, 'nfse.')) {
                $fiscal[substr((string) $key, 5)] = $value;
            }
        }

        return $fiscal;
    }

    protected function issuerFiscalValue(string $key, mixed $default = null): mixed
    {
        return ControllerIsolationState::$settings['nfse.' . $key] ?? $default;
    }

    /** @return array{isReady:bool,checklist:array<string,bool>} */
    protected function companyEmissionReadiness(): array
    {
        return $this->emissionReadiness();
    }

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
