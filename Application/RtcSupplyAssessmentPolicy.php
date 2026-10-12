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
 * Side-effect-free RTC document calendar for one explicitly classified supply.
 *
 * The rule does not infer a supply category from the product name, taxpayer,
 * LC116 number, invoice date, or transport authorization. Payment and tax
 * calculation rules are separate, as is technical XML capability.
 */
final class RtcSupplyAssessmentPolicy
{
    public const SOURCE_URL = 'https://www.cgibs.gov.br/upload/arquivos/202607/31091735-20260730-16h30-ato-conjunto-rfb-cgibs-na-c2-ba-4-260731-090909.pdf';

    private const SOURCE_VERSION = 'Ato Conjunto RFB/CGIBS 4/2026 (30/07/2026)';

    private const DECEMBER_START = '2026-12-01';
    private const SIMPLES_START = '2027-01-01';

    /** @var array<string,string> */
    private const SPECIAL_LC116_PROVISIONS = [
        '0103' => 'art. 1, III, b',
        '0105' => 'art. 1, III, b',
        '0109' => 'art. 1, III, b',
        '1601' => 'art. 1, III, c',
    ];

    /**
     * @return array{required:?bool,effective_date:?string,provision:?string,reason:string}
     */
    public function schedule(FiscalContext $context): array
    {
        $category = trim((string) $context->operationCategory);

        if ($category === 'ordinary_lc116') {
            $rule = $this->ordinaryServiceRule($context->lc116Subitem);
            if ($rule === null) {
                return $this->unknown('lc116_subitem_unverifiable');
            }
        } else {
            $categoryRule = RtcSupplyCategoryPolicy::rule($category);
            if ($categoryRule === null) {
                return $this->unknown('operation_category_unverifiable');
            }
            $rule = [$categoryRule['effective_date'], $categoryRule['provision']];
        }

        return $this->applyEffectiveDate($context, $rule[0], $rule[1]);
    }

    public function assess(FiscalContext $context): FiscalAssessment
    {
        $decisions = [$this->calendarDecision($this->schedule($context))];

        if (RtcSupplyCategoryPolicy::rule(trim((string) $context->operationCategory)) !== null) {
            $decisions[] = $this->unsupportedModalityDecision();
        }

        return new FiscalAssessment($context, $decisions);
    }

    /** @return array{string,string}|null */
    private function ordinaryServiceRule(?string $subitem): ?array
    {
        $service = Lc116Code::normalize($subitem);
        if ($service === '') {
            return null;
        }

        if (isset(self::SPECIAL_LC116_PROVISIONS[$service])) {
            return [self::DECEMBER_START, self::SPECIAL_LC116_PROVISIONS[$service]];
        }

        return ['2026-10-01', 'art. 1, III, d'];
    }

    /**
     * @return array{required:?bool,effective_date:?string,provision:?string,reason:string}
     */
    private function applyEffectiveDate(FiscalContext $context, string $effective, string $provision): array
    {
        // Unknown is never the same as non-optant. Separate IBS/CBS payment
        // elections do not silently change the document calendar of §1.
        if ($context->opcaoSimplesNacional === null) {
            return $this->unknown('simples_option_unverifiable', $provision, $effective);
        }

        if (in_array($context->opcaoSimplesNacional, [2, 3], true)) {
            $effective = self::SIMPLES_START;
            $provision .= ' and art. 1, §1';
        }

        if ($context->taxableEventDate === null) {
            return $this->unknown('taxable_event_date_unverifiable', $provision, $effective);
        }

        $required = $context->taxableEventDate->format('Y-m-d') >= $effective;

        return [
            'required' => $required,
            'effective_date' => $effective,
            'provision' => $provision,
            'reason' => $required ? 'document_obligation_started' : 'before_document_obligation',
        ];
    }

    /**
     * @param array{required:?bool,effective_date:?string,provision:?string,reason:string} $schedule
     */
    private function calendarDecision(array $schedule): FiscalDecision
    {
        $required = $schedule['required'];
        $knownSource = $schedule['provision'] !== null;

        // Unknown inputs and uninspected XML cannot prove an infraction.
        // The final preflight (#429) will test the actual fields before POST.
        return new FiscalDecision(
            ruleId: 'rtc.dfe.nfse.calendar',
            category: 'normative',
            status: $required === false ? 'not_applicable' : 'unverifiable',
            severity: $required === false ? 'info' : 'warn',
            sourceUrl: $knownSource ? self::SOURCE_URL : null,
            sourceProvision: $schedule['provision'],
            sourceVersion: $knownSource ? self::SOURCE_VERSION : null,
            sourcePublishedAt: $knownSource ? new \DateTimeImmutable('2026-07-31') : null,
            effectiveFrom: $schedule['effective_date'] !== null
                ? new \DateTimeImmutable($schedule['effective_date'])
                : null,
            effectiveUntil: null,
            fieldPath: $this->fieldForReason($schedule['reason']),
            correctionCode: $required === true
                ? 'verify_required_ibscbs_document_fields'
                : ($required === null ? $schedule['reason'] : null),
        );
    }

    private function unsupportedModalityDecision(): FiscalDecision
    {
        // A known legal date cannot make the current LC116 DPS serializable.
        return new FiscalDecision(
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

    private function fieldForReason(string $reason): ?string
    {
        return match ($reason) {
            'operation_category_unverifiable' => 'operationCategory',
            'lc116_subitem_unverifiable' => 'lc116Subitem',
            'simples_option_unverifiable' => 'opcaoSimplesNacional',
            'taxable_event_date_unverifiable' => 'taxableEventDate',
            'document_obligation_started' => 'ibsCbs',
            default => null,
        };
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
