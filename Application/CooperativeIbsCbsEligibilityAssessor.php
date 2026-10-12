<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use DateTimeImmutable;
use Modules\Nfse\Contracts\CooperativeSupplyRelation;
use Modules\Nfse\Contracts\FiscalAssessment;
use Modules\Nfse\Contracts\FiscalContext;
use Modules\Nfse\Contracts\FiscalDecision;

/**
 * Read-only screening of the 2027 cooperative IBS/CBS zero-rate hypothesis.
 *
 * This assesses source-backed applicability from verified input facts; it does
 * not set a tax rate, prove an RFB election, or authorize any fiscal POST.
 * An unresolved conflict between statute and regulation is never a pass.
 */
final class CooperativeIbsCbsEligibilityAssessor
{
    private const LAW_URL = 'https://www2.camara.leg.br/legin/fed/leicom/2025/leicomplementar-214-16-janeiro-2025-796905-normaatualizada-pl.html';
    private const DECREE_URL = 'https://www2.camara.leg.br/legin/fed/decret/2026/decreto-12955-29-abril-2026-799019-normaatualizada-pe.html';

    public function assess(FiscalContext $context): FiscalAssessment
    {
        $event = $context->taxableEventDate?->format('Y-m-d');
        if ($event === null) {
            return $this->result($context, 'COOP-ZERO-EVENT-UNKNOWN', 'unverifiable', 'taxableEventDate', 'verify_taxable_event_date', 'art. 271');
        }

        // This deliberately selects only the 2027 horizon. Later years require
        // a newly reviewed, dated policy, rather than silent perpetual reuse.
        if ($event < '2027-01-01') {
            return $this->result($context, 'COOP-ZERO-BEFORE-2027', 'not_applicable', 'taxableEventDate', null, 'art. 271, para. 3');
        }
        if ($event > '2027-12-31') {
            return $this->result($context, 'COOP-ZERO-AFTER-2027', 'unsupported', 'taxableEventDate', 'update_applicable_cooperative_policy', 'art. 271');
        }

        // This application currently represents ordinary LC116 service groups.
        // Other supply types and credit adjustments are owned by their policies.
        if ($context->operationCategory !== 'ordinary_lc116' || $context->lc116Subitem === null) {
            return $this->result($context, 'COOP-ZERO-OPERATION-UNSUPPORTED', 'unsupported', 'operationCategory', 'classify_supported_service', 'art. 271');
        }

        $facts = $context->cooperativeIbsCbsFacts;
        if ($facts === null || $facts->optionConfirmed === null) {
            return $this->result($context, 'COOP-ZERO-ELECTION-UNVERIFIED', 'unverifiable', 'cooperativeIbsCbsFacts.optionConfirmed', 'verify_official_cooperative_election', 'art. 271, para. 3');
        }
        if ($facts->optionConfirmed === false) {
            return $this->result($context, 'COOP-ZERO-NOT-ELECTED', 'not_applicable', 'cooperativeIbsCbsFacts.optionConfirmed', null, 'art. 271, para. 3');
        }
        if ($facts->optionEffectiveFrom === null) {
            return $this->result($context, 'COOP-ZERO-ELECTION-DATE-UNKNOWN', 'unverifiable', 'cooperativeIbsCbsFacts.optionEffectiveFrom', 'verify_election_effective_date', 'art. 271, para. 3');
        }
        if ($facts->optionEffectiveFrom->format('Y-m-d') > $event) {
            return $this->result($context, 'COOP-ZERO-ELECTION-NOT-EFFECTIVE', 'not_applicable', 'cooperativeIbsCbsFacts.optionEffectiveFrom', null, 'art. 271, para. 3');
        }

        // A verified election is necessary but never sufficient. The regulation
        // excludes unrelated supplies, non-cooperative purposes and personal use.
        if ($facts->supplyRelation === CooperativeSupplyRelation::UNRELATED
            || $facts->associationVerifiedAtEvent === false) {
            return $this->result($context, 'COOP-ZERO-NO-ASSOCIATION', 'not_applicable', 'cooperativeIbsCbsFacts.associationVerifiedAtEvent', null, 'art. 271');
        }
        if ($facts->withinCooperativePurpose === false) {
            return $this->result($context, 'COOP-ZERO-OUTSIDE-PURPOSE', 'not_applicable', 'cooperativeIbsCbsFacts.withinCooperativePurpose', null, 'art. 392, III', true);
        }
        if ($facts->forPersonalUseOrConsumption === true) {
            return $this->result($context, 'COOP-ZERO-PERSONAL-USE', 'not_applicable', 'cooperativeIbsCbsFacts.forPersonalUseOrConsumption', null, 'art. 392, IV', true);
        }
        if ($facts->associationVerifiedAtEvent !== true
            || $facts->withinCooperativePurpose !== true
            || $facts->forPersonalUseOrConsumption !== false
            || $facts->supplyRelation === null) {
            return $this->result($context, 'COOP-ZERO-FACTS-INCOMPLETE', 'unverifiable', 'cooperativeIbsCbsFacts', 'verify_operation_scope_and_membership', 'art. 271');
        }

        if ($facts->supplyRelation === CooperativeSupplyRelation::PARTICIPATING_COOPERATIVES) {
            return $this->result($context, 'COOP-ZERO-COOPERATIVE-CHAIN-UNSUPPORTED', 'unsupported', 'cooperativeIbsCbsFacts.supplyRelation', 'verify_cooperative_chain_conditions', 'art. 271, para. 1, I');
        }

        if ($facts->supplyRelation === CooperativeSupplyRelation::COOPERATIVE_TO_ASSOCIATE) {
            if ($context->providerIsCooperative !== true) {
                return $this->result($context, 'COOP-ZERO-PROVIDER-UNVERIFIED', 'unverifiable', 'providerIsCooperative', 'verify_cooperative_provider', 'art. 271, II');
            }
            if ($facts->associateIsRegularIbsCbsTaxpayer === true) {
                return $this->result($context, 'COOP-ZERO-COOP-TO-REGULAR-ASSOCIATE', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null, 'art. 271, II');
            }
            if ($facts->associateIsRegularIbsCbsTaxpayer === false) {
                // The exception for a non-regular associate concerns MATERIAL
                // GOODS from a qualifying agricultural cooperative, not services.
                return $this->result($context, 'COOP-ZERO-COOP-TO-NONREGULAR-SERVICE', 'not_applicable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', null, 'art. 271, II and para. 1, II');
            }
            return $this->result($context, 'COOP-ZERO-ASSOCIATE-REGIME-UNKNOWN', 'unverifiable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', 'verify_associate_regular_status', 'art. 271, II');
        }

        if ($facts->supplyRelation === CooperativeSupplyRelation::ASSOCIATE_TO_COOPERATIVE) {
            if ($facts->associateIsRegularIbsCbsTaxpayer === false) {
                // LC 214 art. 271(I) does not expressly require the regular
                // regime, while Decree 12.955 art. 392(II) excludes it. Neither
                // choosing the favorable reading nor assuming a denial is safe.
                return new FiscalAssessment($context, [
                    $this->decision('COOP-ZERO-CONFLICT-LC271-I', 'unverifiable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', 'resolve_statute_regulation_conflict', 'art. 271, I'),
                    $this->decision('COOP-ZERO-CONFLICT-DEC392-II', 'unverifiable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', 'resolve_statute_regulation_conflict', 'art. 392, II', true),
                ]);
            }
            if ($facts->associateIsRegularIbsCbsTaxpayer === true) {
                return $this->result($context, 'COOP-ZERO-REGULAR-ASSOCIATE-TO-COOP', 'pass', 'cooperativeIbsCbsFacts.supplyRelation', null, 'art. 271, I');
            }
            return $this->result($context, 'COOP-ZERO-ASSOCIATE-REGIME-UNKNOWN', 'unverifiable', 'cooperativeIbsCbsFacts.associateIsRegularIbsCbsTaxpayer', 'verify_associate_regular_status', 'art. 271, I');
        }

        return $this->result($context, 'COOP-ZERO-RELATION-UNSUPPORTED', 'unsupported', 'cooperativeIbsCbsFacts.supplyRelation', 'verify_supply_relation', 'art. 271');
    }

    private function result(FiscalContext $context, string $rule, string $status, string $field, ?string $correction, string $provision, bool $decree = false): FiscalAssessment
    {
        return new FiscalAssessment($context, [
            $this->decision($rule, $status, $field, $correction, $provision, $decree),
        ]);
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
