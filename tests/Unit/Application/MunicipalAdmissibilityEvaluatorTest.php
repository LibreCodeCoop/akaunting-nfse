<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\MunicipalAdmissibilityEvaluator;
use PHPUnit\Framework\TestCase;

final class MunicipalAdmissibilityEvaluatorTest extends TestCase
{
    private function context(): array
    {
        return [
            'company_id' => 1,
            'environment' => 'production',
            'incidence_municipality' => '3303302',
            'competence' => '2026-09-01',
            'national_code' => '010701',
            'municipal_complement' => '123',
            'service_code' => '010701123',
            'dps_id' => 'DPS123',
            'dps_sha256' => str_repeat('a', 64),
        ];
    }

    private function evidence(string $outcome): array
    {
        return [
            'kind' => 'sefin_issuance_result',
            'source_url' => 'https://sefin.nfse.gov.br/SefinNacional/nfse',
            'http_status' => $outcome === 'rejected' ? 400 : 201,
            'consulted_at' => '2026-10-08T19:00:00-03:00',
            'context' => $this->context(),
            'outcome' => $outcome,
            'error_code' => 'E0312',
            'access_key' => 'NfseAccessKey',
        ];
    }

    public function testOfficialMatchedE0312RejectsOnlyTheExactDps(): void
    {
        $evaluation = new MunicipalAdmissibilityEvaluator();
        $matched = $evaluation->evaluate($this->context(), $this->evidence('rejected'));
        self::assertSame('rejected', $matched['decision']);
        self::assertFalse($matched['can_attempt']);
        self::assertFalse($matched['authorized']);

        foreach (['company_id' => 2, 'environment' => 'sandbox', 'incidence_municipality' => '3304557',
            'competence' => '2026-10-01', 'municipal_complement' => '222',
            'service_code' => '010701222', 'dps_id' => 'DPS456',
            'dps_sha256' => str_repeat('b', 64)] as $key => $value) {
            $changed = $this->context();
            $changed[$key] = $value;
            $result = $evaluation->evaluate($changed, $this->evidence('rejected'));
            self::assertSame('unverifiable', $result['decision'], $key);
            self::assertTrue($result['can_attempt'], $key);
        }
    }

    public function testOnlyExplicitMatchedAuthorizationCanBeMarkedAuthorized(): void
    {
        $result = (new MunicipalAdmissibilityEvaluator())->evaluate($this->context(), $this->evidence('authorized'));
        self::assertSame('authorized', $result['decision']);
        self::assertTrue($result['authorized']);
    }

    public function testParametersAreNotAcceptanceEvenWhenPublishedAndRateMatches(): void
    {
        $result = (new MunicipalAdmissibilityEvaluator())->evaluate($this->context(), [
            'kind' => 'adn_municipal_parameters', 'http_status' => 200,
            'aliquota' => ['aliquotas' => [['Aliq' => '5.00']]],
        ]);
        self::assertSame('unverifiable', $result['decision']);
        self::assertTrue($result['can_attempt']);
    }

    public function test404StalePartialOrNetworkFailureNeverReject(): void
    {
        $evaluator = new MunicipalAdmissibilityEvaluator();
        foreach ([
            ['kind' => 'adn_municipal_parameters', 'http_status' => 404],
            ['kind' => 'adn_municipal_parameters', 'stale' => true],
            ['kind' => 'adn_municipal_parameters', 'aliquota' => []],
            ['kind' => 'adn_municipal_parameters', 'fallback_reason' => 'network_failure'],
        ] as $evidence) {
            $result = $evaluator->evaluate($this->context(), $evidence);
            self::assertSame('unverifiable', $result['decision']);
            self::assertTrue($result['can_attempt']);
        }
    }

    public function testMissingIncidenceOrWrongServiceCodeIsUnverifiable(): void
    {
        $context = $this->context();
        $context['incidence_municipality'] = '';
        self::assertSame(
            'municipal_context_incomplete',
            (new MunicipalAdmissibilityEvaluator())->evaluate($context)['reason']
        );
        $context = $this->context();
        $context['service_code'] = '010701000';
        self::assertSame(
            'unverifiable',
            (new MunicipalAdmissibilityEvaluator())->evaluate($context, $this->evidence('rejected'))['decision']
        );
    }

    public function testUntrustedNonSefinUrlAndMissingDpsHashCannotBeDecisive(): void
    {
        $evidence = $this->evidence('rejected');
        $evidence['source_url'] = 'https://example.com/nfse';
        self::assertSame(
            'unverifiable',
            (new MunicipalAdmissibilityEvaluator())->evaluate($this->context(), $evidence)['decision']
        );
        $context = $this->context();
        $context['dps_sha256'] = '';
        self::assertSame(
            'unverifiable',
            (new MunicipalAdmissibilityEvaluator())->evaluate($context, $this->evidence('rejected'))['decision']
        );
    }
}
