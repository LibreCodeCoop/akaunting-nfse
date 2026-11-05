<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use App\Models\Document\Document as Invoice;
use Modules\Nfse\Contracts\FiscalAssessment;
use Modules\Nfse\Contracts\FiscalDecision;
use Modules\Nfse\Support\InvoiceFiscalAssessmentContextResolver;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;

/**
 * One typed fiscal assessment for the actual outgoing DPS and fiscal group.
 * Existing municipal, regime, RTC, IBS/CBS and NT007 policies retain ownership
 * of their rules. This service never transmits or creates another journal.
 */
final class FinalFiscalAssessmentPreflight
{
    public function __construct(
        private readonly InvoiceFiscalAssessmentContextResolver $contexts = new InvoiceFiscalAssessmentContextResolver(),
        private readonly FiscalAssessmentComposer $composer = new FiscalAssessmentComposer(),
        private readonly FinalDpsFederalEvidenceValidator $federal = new FinalDpsFederalEvidenceValidator(),
        private readonly SpecialTaxRegimePolicy $regime = new SpecialTaxRegimePolicy(),
        private readonly FinalDpsIbsCbsReadiness $ibsCbs = new FinalDpsIbsCbsReadiness(),
    ) {
    }

    /**
     * @param array<string,mixed> $municipal
     */
    public function assess(Invoice $invoice, DpsData $dps, ?string $groupKey, array $municipal): FiscalAssessment
    {
        $context = $this->contexts->resolve(
            $invoice,
            $dps,
            $groupKey,
            is_array($municipal['context'] ?? null) ? $municipal['context'] : [],
        );
        $decisions = $this->composer->assess($context)->decisions;

        $regime = $this->regime->evaluate(
            $dps->regimeEspecialTributacao,
            $dps->opcaoSimplesNacional,
            $dps->tributacaoIssqn ?? 1,
            $context->competenceDate?->format('Y-m-d'),
            $dps->regimeApuracaoSimplesNacional,
        );
        if (!$regime['can_emit']) {
            $decisions[] = $this->decision('NFSE-REGIME-INVALID', 'protocol', 'unsupported', 'block', 'regimeEspecialTributacao', $regime['reason']);
        } elseif ($regime['municipal_review_required']) {
            $decisions[] = $this->decision('NFSE-REGIME-MUNICIPAL-REVIEW', 'normative', 'unverifiable', 'warn', 'regimeEspecialTributacao', $regime['reason']);
        }

        if (($municipal['can_attempt'] ?? false) !== true) {
            $decisions[] = $this->decision(
                'NFSE-MUNICIPAL-PREFLIGHT-BLOCKED',
                'protocol',
                'unverifiable',
                'block',
                'incidenceMunicipalityIbge',
                (string) ($municipal['reason'] ?? 'municipal_evidence_unverifiable'),
            );
        } elseif (($municipal['municipal']['decision'] ?? '') === 'unverifiable') {
            $decisions[] = $this->decision(
                'NFSE-MUNICIPAL-EVIDENCE-UNVERIFIABLE',
                'normative',
                'unverifiable',
                'warn',
                'incidenceMunicipalityIbge',
                'verify_official_municipal_parameters',
            );
        }

        $ibs = $this->ibsCbs->evaluate($dps, $context);
        if (!$ibs['isReady']) {
            $decisions[] = $this->decision(
                'NFSE-IBS-CBS-FINAL-DPS-BLOCKED',
                'protocol',
                'unverifiable',
                'block',
                implode(',', $ibs['missing']),
                $ibs['reason'],
            );
        }

        foreach ($this->federal->inspect($context, $dps) as $decision) {
            $decisions[] = $decision;
        }

        return new FiscalAssessment($context, $decisions);
    }

    /**
     * @param array<string,mixed> $municipal
     */
    public function assertReady(Invoice $invoice, DpsData $dps, ?string $groupKey, array $municipal): FiscalAssessment
    {
        try {
            $assessment = $this->assess($invoice, $dps, $groupKey, $municipal);
        } catch (\DomainException|\InvalidArgumentException $error) {
            throw new FiscalIssuanceBlocked('Fiscal assessment blocked: ' . $error->getMessage(), previous: $error);
        }

        $blocked = $this->composer->blockingDecisions($assessment);
        if ($blocked !== []) {
            $first = $blocked[0];
            $message = 'Fiscal assessment blocked: ' . $first->ruleId
                . ' (' . ($first->correctionCode ?? $first->fieldPath ?? 'review_required') . ')';

            if ($first->ruleId === 'NFSE-MUNICIPAL-PREFLIGHT-BLOCKED') {
                throw new MunicipalIssuanceBlocked($message);
            }
            throw new FiscalIssuanceBlocked($message);
        }

        return $assessment;
    }

    /**
     * Bounded, immutable evidence summary. Never expose raw tax rows, identity
     * documents, XML or the operator's free-text source reference.
     *
     * @return array<string,mixed>
     */
    public function summary(FiscalAssessment $assessment): array
    {
        $context = $assessment->context;

        return [
            'scope' => 'final_first_post',
            'commercial_date' => $context->commercialDate?->format('Y-m-d'),
            'commercial_date_source' => $context->commercialDateSource,
            'competence_date' => $context->competenceDate?->format('Y-m-d'),
            'competence_source' => $context->competenceSource,
            'taxable_event_date' => $context->taxableEventDate?->format('Y-m-d'),
            'taxable_event_source' => $context->taxableEventSource,
            'decisions' => array_map(static fn (FiscalDecision $decision): array => [
                'rule_id' => $decision->ruleId,
                'status' => $decision->status,
                'severity' => $decision->severity,
                'field' => $decision->fieldPath,
                'correction_code' => $decision->correctionCode,
                'source_url' => $decision->sourceUrl,
                'source_version' => $decision->sourceVersion,
                'source_provision' => $decision->sourceProvision,
            ], $assessment->decisions),
        ];
    }

    private function decision(
        string $ruleId,
        string $category,
        string $status,
        string $severity,
        ?string $field,
        ?string $correction,
    ): FiscalDecision {
        return new FiscalDecision(
            ruleId: $ruleId,
            category: $category,
            status: $status,
            severity: $severity,
            sourceUrl: null,
            sourceProvision: null,
            sourceVersion: null,
            sourcePublishedAt: null,
            effectiveFrom: null,
            effectiveUntil: null,
            fieldPath: $field,
            correctionCode: $correction,
        );
    }
}
