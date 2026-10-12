<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use DateTimeImmutable;
use Modules\Nfse\Contracts\CooperativeIbsCbsFacts;
use Modules\Nfse\Contracts\CooperativeSupplyRelation;
use Modules\Nfse\Contracts\FiscalAssessment;
use Modules\Nfse\Contracts\FiscalContext;
use Modules\Nfse\Contracts\FiscalDecision;

/**
 * Read-only, 2027-only screening of the cooperative IBS/CBS hypothesis.
 * A passing decision does not set a tax rate, validate official election
 * evidence, or authorize issuance. Normative conflict remains inconclusive.
 */
final class CooperativeIbsCbsEligibilityAssessor
{
    private const LAW_URL = 'https://www2.camara.leg.br/legin/fed/leicom/2025/leicomplementar-214-16-janeiro-2025-796905-normaatualizada-pl.html';
    private const DECREE_URL = 'https://www2.camara.leg.br/legin/fed/decret/2026/decreto-12955-29-abril-2026-799019-normaatualizada-pe.html';

    public function assess(FiscalContext $context): FiscalAssessment
    {
        // Keep precedence explicit: known exclusions precede missing evidence;
        // downstream relations are evaluated only for fully classified facts.
        $decision = $this->periodRule($context)
            ?? $this->serviceRule($context)
            ?? $this->electionRule($context)
            ?? $this->exclusionRule($context)
            ?? $this->missingFactsRule($context);

        if ($decision !== null) {
            return new FiscalAssessment($context, [$decision]);
        }

        $facts = $context->cooperativeIbsCbsFacts;
        if ($facts === null) {
            // Defense in depth; electionRule already returns on missing facts.
            return new FiscalAssessment($context, [$this->decision(
                'COOP-ZERO-ELECTION-UNVERIFIED',
                'unverifiable',
                'cooperativeIbsCbsFacts.optionConfirmed',
                'verify_official_cooperative_election',
                'art. 271, para. 3',
            )]);
        }

        return new FiscalAssessment($context, $this->relationDecisions($context, $facts));
    }

    private function periodRule(FiscalContext $context): ?FiscalDecision
    {
        $event = $context->taxableEventDate?->format('Y-m-d');

        return match (true) {
            $event === null => $this->decision('COOP-ZERO-EVENT-UNKNOWN', 'unverifiable', 'taxableEventDate', 'verify_taxable_event_date', 'art. 271'),
            $event < '2027-01-01' => $this->decision('COOP-ZERO-BEFORE-2027', 'not_applicable', 'taxableEventDate', null, 'art. 271, para. 3'),
            $event > '2027-12-31' => $this->decision('COOP-ZERO-AFTER-2027', 'unsupported', 'taxableEventDate', 'update_applicable_cooperative_policy', 'art. 271'),
            default => null,
        };
    }

    private function serviceRule(FiscalContext $context): ?FiscalDecision
    {
        if ($context->operationCategory === 'ordinary_lc116'
            && $context->lc116Subitem !== null && trim($context->lc116Subitem) !== '') {
            return null;
        }

        return $this->decision('COOP-ZERO-OPERATION-UNSUPPORTED', 'unsupported', 'operationCategory', 'classify_supported_service', 'art. 271');
    }

    private function electionRule(FiscalContext $context): ?FiscalDecision
    {
        $facts = $context->cooperativeIbsCbsFacts;
        if ($facts?->optionConfirmed === null) {
            return $this->decision('COOP-ZERO-ELECTION-UNVERIFIED', 'unverifiable', 'cooperativeIbsCbsFacts.optionConfirmed', 'verify_official_cooperative_election', 'art. 271, para. 3');
        }

        if ($facts->optionConfirmed === false) {
            return $this->decision('COOP-ZERO-NOT-ELECTED', 'not_applicable', 'cooperativeIbsCbsFacts.optionConfirmed', null, 'art. 271, para. 3');
        }

        // A true option already requires a date and both filed forms in the
        // validated CooperativeIbsCbsFacts constructor; do not recheck them.
        if ($facts->optionEffectiveFrom !== null && $context->taxableEventDate !== null
            && $facts->optionEffectiveFrom > $context->taxableEventDate) {
            return $this->decision('COOP-ZERO-ELECTION-NOT-EFFECTIVE', 'not_applicable', 'cooperativeIbsCbsFacts.optionEffectiveFrom', null, 'art. 271, para. 3');
        }

        return null;
    }

    private function exclusionRule(FiscalContext $context): ?FiscalDecision
    {
        $facts = $context->cooperativeIbsCbsFacts;

        return match (true) {
            $facts?->supplyRelation === CooperativeSupplyRelation::UNRELATED || $facts?->associationVerifiedAtEvent === false =>
                $this->decision('COOP-ZERO-NO-ASSOCIATION', 'not_applicable', 'cooperativeIbsCbsFacts.associationVerifiedAtEvent', null, 'art. 271'),
            $facts?->withinCooperativePurpose === false =>
                $this->decision('COOP-ZERO-OUTSIDE-PURPOSE', 'not_applicable', 'cooperativeIbsCbsFacts.withinCooperativePurpose', null, 'art. 392, III', true),
            $facts?->forPersonalUseOrConsumption === true =>
                $this->decision('COOP-ZERO-PERSONAL-USE', 'not_applicable', 'cooperativeIbsCbsFacts.forPersonalUseOrConsumption', null, 'art. 392, IV', true),
            default => null,
        };
    }

    private function missingFactsRule(FiscalContext $context): ?FiscalDecision
    {
        $facts = $context->cooperativeIbsCbsFacts;
        if ($facts !== null && $facts->supplyRelation !== null
            && $facts->associationVerifiedAtEvent === true
            && $facts->withinCooperativePurpose === true
            && $facts->forPersonalUseOrConsumption === false) {
            return null;
        }

        return $this->decision('COOP-ZERO-FACTS-INCOMPLETE', 'unverifiable', 'cooperativeIbsCbsFacts', 'verify_operation_scope_and_membership', 'art. 271');
    }

    /** @return list<FiscalDecision> */
    private function relationDecisions(FiscalContext $context, CooperativeIbsCbsFacts $facts): array
    {
        return match ($facts->supplyRelation) {
            CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE => [$this->cooperativeToAssociate($context, $facts)],
            CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE => $this->associateToCooperative($facts),
            CooperativeSupplyRelation::PARTICIPATING_COOPERATIVES => [$this->decision(
                'COOP-ZERO-COOPERATIVE-CHAIN-UNSUPPORTED',
                'unsupported',
                'cooperativeIbsCbsFacts.supplyRelation',
                'verify_cooperative_chain_conditions',
                'art. 271, para. 1, I',
            )],
            // Guard for future enum expansion / a changed upstream contract.
            default => [$this->decision('COOP-ZERO-RELATION-UNSUPPORTED', 'unsupported', 'cooperativeIbsCbsFacts.supplyRelation', 'verify_supply_relation', 'art. 271')],
        };
    }

    private function cooperativeToAssociate(FiscalContext $context, CooperativeIbsCbsFacts $facts): FiscalDecision
    {
        if ($context->providerIsCooperative !== true) {
            return $this->decision('COOP-ZERO-PROVIDER-UNVERIFIED', 'unverifiable', 'providerIsCooperative', 'verify_cooperative_provider', 'art. 271, II');
        }

        return match ($facts->associateIsRegularIbsCbsTaxpayer) {
            true => $this->decision('COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null, 'art. 271, II'),
            false => $this->decision('COOP-ZERO-COOP-TO-NONREGULAR-SERVICE', 'not_applicable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', null, 'art. 271, II and para. 1, II'),
            null => $this->decision('COOP-ZERO-ASSOCIATE-REGIME-UNKNOWN', 'unverifiable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', 'verify_associate_regular_status', 'art. 271, II'),
        };
    }

    /** @return list<FiscalDecision> */
    private function associateToCooperative(CooperativeIbsCbsFacts $facts): array
    {
        if ($facts->associateIsRegularIbsCbsTaxpayer === false) {
            // LC 214/2025 art. 271(I) and Decree 12.955/2026 art. 392(II)
            // differ on non-regular associates. No silent resolution.
            return [
                $this->decision('COOP-ZERO-CONFLICT-LC271-I', 'unverifiable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', 'resolve_statute_regulation_conflict', 'art. 271, I'),
                $this->decision('COOP-ZERO-CONFLICT-DEC392-II', 'unverifiable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', 'resolve_statute_regulation_conflict', 'art. 392, II', true),
            ];
        }

        return [$facts->associateIsRegularIbsCbsTaxpayer === true
            ? $this->decision('COOP-ZERO-REGULAR-ASSOCIATE-TO-COOP', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null, 'art. 271, I')
            : $this->decision('COOP-ZERO-ASSOCIATE-REGIME-UNKNOWN', 'unverifiable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', 'verify_associate_regular_status', 'art. 271, I')];
    }

    private function decision(string $rule, string $status, string $field, ?string $correction, string $provision, bool $decree = false): FiscalDecision
    {
        return new FiscalDecision(
            ruleId: $rule,
            category: 'normative',
            status: $status,
            severity: in_array($status, ['pass', 'not_applicable'], true) ? 'info' : 'warn',
            sourceUrl: $decree ? self::DECREE_URL : self::LAW_URL,
            sourceProvision: $provision,
            sourceVersion: $decree ? 'Decreto 12.955/2026' : 'LC 214/2025, consolidada com LC 227/2026',
            sourcePublishedAt: new DateTimeImmutable($decree ? '2026-04-29' : '2025-01-16'),
            effectiveFrom: new DateTimeImmutable('2027-01-01'),
            effectiveUntil: new DateTimeImmutable('2027-12-31'),
            fieldPath: $field,
            correctionCode: $correction,
        );
    }
}
