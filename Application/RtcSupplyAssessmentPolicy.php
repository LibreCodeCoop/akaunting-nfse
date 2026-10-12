<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Contracts\FiscalAssessment;
use Modules\Nfse\Contracts\FiscalContext;
use Modules\Nfse\Contracts\FiscalDecision;
use Modules\Nfse\Support\Lc116Code;

/**
 * Calendar-only RTC assessment for one explicitly classified fiscal operation.
 *
 * Never infers an operation from commercial descriptions, LC 116 alone,
 * a provider's legal name or a valid XML response. The DFe start date is
 * measured against the taxable event, not the invoice issue or transmission.
 * This is not a tax calculator or a proof that a DPS schema is activated.
 */
final class RtcSupplyAssessmentPolicy
{
    public const SOURCE_URL = 'https://www.cgibs.gov.br/upload/arquivos/202607/31091735-20260730-16h30-ato-conjunto-rfb-cgibs-na-c2-ba-4-260731-090909.pdf';

    private const SOURCE_VERSION = 'Ato Conjunto RFB/CGIBS 4/2026 (30/07/2026)';

    /** @var array<string,string> */
    private const PROVISIONS = [
        'digital_platform' => 'art. 1, III, a',
        'non_iss_intangible' => 'art. 1, III, e',
        'condominium_revenue' => 'art. 1, III, f',
        'lease' => 'art. 1, III, g',
        'residual_service' => 'art. 1, III, h',
    ];

    /** @var list<string> */
    private const DEFERRED_SUBITEMS = ['0103', '0105', '0109', '1601'];

    /**
     * @return array{required:?bool,effective_date:?string,provision:?string,reason:string}
     */
    public function schedule(FiscalContext $context): array
    {
        $category = trim((string) $context->operationCategory);

        if ($category === 'ordinary_lc116') {
            $service = Lc116Code::normalize($context->lc116Subitem);
            if (preg_match('/^[0-9]{4}$/D', $service) !== 1) {
                return $this->unknown('lc116_subitem_unverifiable');
            }

            $deferred = in_array($service, self::DEFERRED_SUBITEMS, true);
            $provision = $deferred
                ? ($service === '1601' ? 'art. 1, III, c' : 'art. 1, III, b')
                : 'art. 1, III, d';
            $effective = $deferred ? '2026-12-01' : '2026-10-01';
        } elseif (isset(self::PROVISIONS[$category])) {
            $provision = self::PROVISIONS[$category];
            $effective = '2026-12-01';
        } else {
            return $this->unknown('operation_category_unverifiable');
        }

        // DPS opSimpNac must be an observed, verified input. The taxpayer's
        // IBS/CBS payment election does not change the DFe calendar in §1.
        if (!in_array($context->opcaoSimplesNacional, [1, 2, 3], true)) {
            return $this->unknown('simples_option_unverifiable', $provision);
        }

        if (in_array($context->opcaoSimplesNacional, [2, 3], true)) {
            $effective = '2027-01-01';
            $provision .= ' and art. 1, §1';
        }

        if ($context->taxableEventDate === null) {
            return $this->unknown('taxable_event_date_unverifiable', $provision, $effective);
        }

        // The Ato applies to taxable events occurring from the boundary,
        // inclusively; competence is a separate, independent fact.
        $required = $context->taxableEventDate->format('Y-m-d') >= $effective;

        return [
            'required' => $required,
            'effective_date' => $effective,
            'provision' => $provision,
            'reason' => $required ? 'document_obligation_started' : 'before_document_obligation',
        ];
    }

    /**
     * Produces typed, source-backed *diagnostics*, not issuance permission.
     * A required document still needs a separate check of its actual IBS/CBS
     * information, and protocol activation belongs to the transport package.
     */
    public function assess(FiscalContext $context): FiscalAssessment
    {
        $schedule = $this->schedule($context);
        $required = $schedule['required'];
        $knownProvision = $schedule['provision'] !== null;

        $field = match ($schedule['reason']) {
            'operation_category_unverifiable' => 'operationCategory',
            'lc116_subitem_unverifiable' => 'lc116Subitem',
            'simples_option_unverifiable' => 'opcaoSimplesNacional',
            'taxable_event_date_unverifiable' => 'taxableEventDate',
            'document_obligation_started' => 'ibsCbs',
            default => null,
        };

        $decisions = [
            new FiscalDecision(
                ruleId: 'rtc.dfe.nfse.calendar',
                category: 'normative',
                // Calendar applicability alone cannot prove a compliant XML.
                status: $required === false ? 'not_applicable' : 'unverifiable',
                severity: $required === false ? 'info' : 'block',
                sourceUrl: $knownProvision ? self::SOURCE_URL : null,
                sourceProvision: $schedule['provision'],
                sourceVersion: $knownProvision ? self::SOURCE_VERSION : null,
                sourcePublishedAt: $knownProvision ? new \DateTimeImmutable('2026-07-31') : null,
                effectiveFrom: $schedule['effective_date'] !== null
                    ? new \DateTimeImmutable($schedule['effective_date'])
                    : null,
                effectiveUntil: null,
                fieldPath: $field,
                correctionCode: $required === true
                    ? 'verify_required_ibscbs_document_fields'
                    : ($required === null ? $schedule['reason'] : null),
            ),
        ];

        $category = trim((string) $context->operationCategory);
        if (isset(self::PROVISIONS[$category])) {
            // Current module's LC116 DPS route cannot represent these
            // modalities. This is a technical limit, NOT a legal exemption.
            $decisions[] = new FiscalDecision(
                ruleId: 'rtc.dfe.nfse.modality',
                category: 'protocol',
                status: 'unsupported',
                severity: 'block',
                sourceUrl: null,
                sourceProvision: null,
                sourceVersion: null,
                sourcePublishedAt: null,
                effectiveFrom: null,
                effectiveUntil: null,
                fieldPath: 'operationCategory',
                correctionCode: 'unsupported_current_lc116_dps_modality',
            );
        }

        return new FiscalAssessment($context, $decisions);
    }

    /**
     * @return array{required:null,effective_date:?string,provision:?string,reason:string}
     */
    private function unknown(string $reason, ?string $provision = null, ?string $effectiveDate = null): array
    {
        return [
            'required' => null,
            'effective_date' => $effectiveDate,
            'provision' => $provision,
            'reason' => $reason,
        ];
    }
}
