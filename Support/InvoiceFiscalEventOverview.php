<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Document\Document as Invoice;
use Modules\Nfse\Application\FiscalAssessmentComposer;
use Modules\Nfse\Application\InvoiceDpsIdentity;
use Modules\Nfse\Application\SimplesOptionCode;
use Modules\Nfse\Contracts\FiscalContext;

/**
 * Read-only, RTC-calendar-only preview for the native invoice panel and API.
 * It cannot certify a DPS that has not yet been built or transmitted.
 */
final class InvoiceFiscalEventOverview
{
    /**
     * @return list<array<string,mixed>>
     */
    public function forInvoice(Invoice $invoice): array
    {
        $companyId = (int) ($invoice->company_id ?? 0);
        $invoiceId = (int) ($invoice->id ?? 0);
        $active = function_exists('company_id') ? (int) company_id() : 0;

        if ($companyId <= 0 || $invoiceId <= 0 || $active <= 0 || $active !== $companyId
            || (string) ($invoice->type ?? '') !== 'invoice') {
            throw new \Illuminate\Auth\Access\AuthorizationException('Fiscal event overview is outside the active company.');
        }

        try {
            $settings = (new NfseCompanyFiscalSettings())->all($companyId);
            $simples = SimplesOptionCode::observed($settings['opcao_simples_nacional'] ?? null);
        } catch (\DomainException) {
            $simples = null;
        }

        $competence = (new InvoiceDpsIdentity())->competenceDate($invoice);
        $competenceDate = $competence === null ? null : new \DateTimeImmutable($competence);
        $results = [];

        foreach ((new InvoiceFiscalContextResolver())->groups($invoice) as $group) {
            $key = trim((string) ($group['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $evidence = (new InvoiceFiscalEventEvidence())->latest($companyId, $invoiceId, $key);
            $eventDate = $evidence === null ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $evidence['date']);
            if ($eventDate === false) {
                $eventDate = null;
            }

            $context = new FiscalContext(
                companyId: $companyId,
                fiscalGroupKey: $key,
                taxableEventDate: $eventDate,
                competenceDate: $competenceDate,
                providerRegime: null,
                operationCategory: trim((string) ($group['rtc_supply_category'] ?? '')) ?: null,
                lc116Subitem: trim((string) ($group['item_lista_servico'] ?? '')) ?: null,
                nationalTaxCode: trim((string) ($group['codigo_tributacao_nacional'] ?? '')) ?: null,
                nbsCode: trim((string) ($group['codigo_nbs'] ?? '')) ?: null,
                incidenceMunicipalityIbge: null,
                takerType: null,
                takerMunicipalityIbge: null,
                takerCountryIso2: null,
                takerIsSimples: null,
                grossServiceCents: ExactTaxDecimal::cents((string) ($group['amount'] ?? '0.00')),
                opcaoSimplesNacional: $simples,
                taxableEventSource: $eventDate === null ? null : 'operator_attestation',
                competenceSource: 'akaunting.documents.issued_at',
            );

            $assessment = (new FiscalAssessmentComposer())->assess($context);
            $results[] = [
                'group_key' => $key,
                'service_code' => (string) ($group['item_lista_servico'] ?? ''),
                'amount' => (string) ($group['amount'] ?? ''),
                'taxable_event_date' => $eventDate?->format('Y-m-d'),
                'evidence_kind' => $eventDate === null ? null : 'operator_attestation',
                'diagnostics' => array_map(static fn ($decision): array => [
                    'rule_id' => $decision->ruleId,
                    'status' => $decision->status,
                    'severity' => $decision->severity,
                    'field' => $decision->fieldPath,
                    'correction_code' => $decision->correctionCode,
                ], $assessment->decisions),
            ];
        }

        return $results;
    }
}
