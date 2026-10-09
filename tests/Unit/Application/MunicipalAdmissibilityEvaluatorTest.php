<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\MunicipalAdmissibilityEvaluator;
use Modules\Nfse\Application\OfficialMunicipalIssuanceEvidence;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\IssuanceException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NfseErrorCode;
use PHPUnit\Framework\TestCase;

final class MunicipalAdmissibilityEvaluatorTest extends TestCase
{
    /** @return array<string,mixed> */
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

    private function officialRejection(int $status = 422, string $code = 'E0312'): IssuanceException
    {
        return new IssuanceException(
            'SEFIN rejected issuance',
            NfseErrorCode::IssuanceRejected,
            $status,
            ['erros' => [[
                'codigo' => $code,
                'descricao' => 'O codigo de tributacao nacional nao esta administrado no municipio de incidencia na competencia da DPS.',
            ]]],
        );
    }

    private function timestamp(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-09T01:00:00-03:00');
    }

    private function rejectionEvidence(
        ?array $context = null,
        string $environment = 'production',
    ): OfficialMunicipalIssuanceEvidence {
        $result = OfficialMunicipalIssuanceEvidence::fromRejection(
            $this->officialRejection(),
            $context ?? $this->context(),
            $environment,
            $this->timestamp(),
        );
        self::assertInstanceOf(OfficialMunicipalIssuanceEvidence::class, $result);

        return $result;
    }

    public function testOfficialMatchedE0312RejectsOnlyExactDps(): void
    {
        $evaluation = new MunicipalAdmissibilityEvaluator();
        $rejection = $this->rejectionEvidence();
        $matched = $evaluation->evaluate($this->context(), $rejection);
        self::assertSame('rejected', $matched['decision']);
        self::assertSame('exact', $matched['binding']);
        self::assertSame('issuer_exception', $matched['verified_via']);
        self::assertFalse($matched['can_attempt']);
        self::assertFalse($matched['authorized']);

        foreach ([
            'company_id' => 2,
            'environment' => 'sandbox',
            'incidence_municipality' => '3304557',
            'competence' => '2026-10-01',
            'national_code' => '020201',
            'municipal_complement' => '222',
            'service_code' => '010701222',
            'dps_id' => 'DPS456',
            'dps_sha256' => str_repeat('b', 64),
        ] as $key => $value) {
            $changed = $this->context();
            $changed[$key] = $value;
            $result = $evaluation->evaluate($changed, $rejection);
            self::assertSame('unverifiable', $result['decision'], $key);
            self::assertTrue($result['can_attempt'], $key);
        }
    }

    public function testOnlyTypedMatchingIssuedReceiptCanBeMarkedAuthorized(): void
    {
        $receipt = new ReceiptData(
            nfseNumber: '125',
            chaveAcesso: 'access-key-125',
            dataEmissao: '2026-09-01',
        );
        $evidence = OfficialMunicipalIssuanceEvidence::fromReceipt(
            $receipt,
            $this->context(),
            'production',
            $this->timestamp(),
        );
        self::assertInstanceOf(OfficialMunicipalIssuanceEvidence::class, $evidence);
        self::assertNull($evidence->attributes()['http_status']);

        $result = (new MunicipalAdmissibilityEvaluator())->evaluate($this->context(), $evidence);
        self::assertSame('authorized', $result['decision']);
        self::assertTrue($result['authorized']);
        self::assertTrue($result['can_attempt']);
    }

    public function testForgedIssuerShapedArrayIsNeverAcceptedOrRejected(): void
    {
        $evidence = $this->rejectionEvidence()->attributes();
        $evidence['source_url'] = 'https://sefin.nfse.gov.br/SefinNacional/nfse';
        $evaluation = new MunicipalAdmissibilityEvaluator();

        self::assertSame('untrusted_official_claim', $evaluation->evaluate($this->context(), $evidence)['reason']);
        self::assertSame('unverifiable', $evaluation->evaluate($this->context(), $evidence)['decision']);
        $evidence['outcome'] = 'authorized';
        $evidence['access_key'] = 'forged';
        self::assertFalse($evaluation->evaluate($this->context(), $evidence)['authorized']);
    }

    public function testMunicipalParameterResults404AndStaleCannotDecideAuthorization(): void
    {
        $evaluation = new MunicipalAdmissibilityEvaluator();
        foreach ([
            ['kind' => 'adn_municipal_parameters', 'http_status' => 200,
                'aliquota' => ['aliquotas' => [['Aliq' => '5.00']]]],
            ['kind' => 'adn_municipal_parameters', 'http_status' => 404],
            ['kind' => 'adn_municipal_parameters', 'stale' => true],
            ['kind' => 'adn_municipal_parameters', 'aliquota' => []],
            ['kind' => 'adn_municipal_parameters', 'fallback_reason' => 'network_failure'],
        ] as $evidence) {
            $result = $evaluation->evaluate($this->context(), $evidence);
            self::assertSame('unverifiable', $result['decision']);
            self::assertFalse($result['authorized']);
            self::assertTrue($result['can_attempt']);
        }
    }

    public function testUnrecognizedRejectionAndUncertainTransportAreNotE0312(): void
    {
        $other = OfficialMunicipalIssuanceEvidence::fromRejection(
            $this->officialRejection(400, 'E0040'),
            $this->context(),
            'production',
            $this->timestamp(),
        );
        self::assertInstanceOf(OfficialMunicipalIssuanceEvidence::class, $other);
        self::assertSame(
            'unverifiable',
            (new MunicipalAdmissibilityEvaluator())->evaluate($this->context(), $other)['decision'],
        );

        self::assertNull(OfficialMunicipalIssuanceEvidence::fromRejection(
            $this->officialRejection(503),
            $this->context(),
            'production',
            $this->timestamp(),
        ));
    }

    public function testMissingMunicipalityOrInvalidCodeIsUnverifiable(): void
    {
        $context = $this->context();
        $context['incidence_municipality'] = '';
        self::assertSame(
            'municipal_context_incomplete',
            (new MunicipalAdmissibilityEvaluator())->evaluate($context)['reason'],
        );
        $context = $this->context();
        $context['service_code'] = '010701000';
        self::assertSame(
            'unverifiable',
            (new MunicipalAdmissibilityEvaluator())->evaluate($context, $this->rejectionEvidence())['decision'],
        );
    }

    public function testWrongEnvironmentAndAbsentDpsFingerprintRemainUnverifiable(): void
    {
        $rejection = $this->rejectionEvidence($this->context(), 'sandbox');
        $result = (new MunicipalAdmissibilityEvaluator())->evaluate($this->context(), $rejection);
        self::assertSame('unverifiable', $result['decision']);

        $context = $this->context();
        $context['dps_sha256'] = '';
        self::assertSame(
            'unverifiable',
            (new MunicipalAdmissibilityEvaluator())->evaluate($context, $this->rejectionEvidence())['decision'],
        );
    }
}
