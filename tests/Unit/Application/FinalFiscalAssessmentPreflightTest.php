<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use App\Models\Document\Document as Invoice;
use Modules\Nfse\Application\FinalFiscalAssessmentPreflight;
use Modules\Nfse\Application\FiscalIssuanceBlocked;
use Modules\Nfse\Application\MunicipalIssuanceBlocked;
use Modules\Nfse\Support\InvoiceFiscalAssessmentContextResolver;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use PHPUnit\Framework\TestCase;

final class FinalFiscalAssessmentPreflightTest extends TestCase
{
    public function testAttestedEventBeforeRtcStartDoesNotInheritLaterInvoiceObligation(): void
    {
        $assessment = self::preflight('2026-09-30')->assertReady(
            self::invoice(),
            self::dps(),
            'group-a',
            self::municipal(),
        );

        self::assertSame('not_applicable', $assessment->decisions[0]->status);
        self::assertSame('2026-09-30', $assessment->context->taxableEventDate?->format('Y-m-d'));
        self::assertSame('2026-11-02', $assessment->context->competenceDate?->format('Y-m-d'));
    }

    public function testFinalSummaryRecordsProvenanceWithoutRawTaxEvidence(): void
    {
        $preflight = self::preflight('2026-09-30');
        $assessment = $preflight->assess(
            self::invoice(),
            self::dps(),
            'group-a',
            self::municipal(),
        );

        $summary = $preflight->summary($assessment);
        self::assertSame('final_first_post', $summary['scope']);
        self::assertSame('2026-09-30', $summary['taxable_event_date']);
        self::assertSame('operator_attestation', $summary['taxable_event_source']);
        self::assertSame('2026-11-02', $summary['competence_date']);
        self::assertArrayHasKey('rule_id', $summary['decisions'][0]);
        self::assertArrayNotHasKey('taxFacts', $summary);
        self::assertArrayNotHasKey('source_reference', $summary);
    }

    public function testAttestedEventAfterRtcStartBlocksIncompleteDpsBeforeTransmission(): void
    {
        $this->expectException(FiscalIssuanceBlocked::class);
        $this->expectExceptionMessage('NFSE-IBS-CBS-FINAL-DPS-BLOCKED');

        self::preflight('2026-10-02')->assertReady(
            self::invoice(),
            self::dps(),
            'group-a',
            self::municipal(),
        );
    }

    public function testUnknownEventNeverBecomesAConclusiveCalendarDecision(): void
    {
        $assessment = self::preflight(null)->assess(
            self::invoice(),
            self::dps(),
            'group-a',
            self::municipal(),
        );

        self::assertSame('unverifiable', $assessment->decisions[0]->status);
        self::assertSame('taxable_event_date_unverifiable', $assessment->decisions[0]->correctionCode);
        self::assertContains(
            'NFSE-IBS-CBS-FINAL-DPS-BLOCKED',
            array_map(static fn ($decision): string => $decision->ruleId, $assessment->decisions),
        );
    }

    public function testOfficialMunicipalRejectionCannotBeOverriddenByPermissiveGateway(): void
    {
        $this->expectException(MunicipalIssuanceBlocked::class);
        $this->expectExceptionMessage('NFSE-MUNICIPAL-PREFLIGHT-BLOCKED');

        self::preflight('2026-09-30')->assertReady(
            self::invoice(),
            self::dps(),
            'group-a',
            self::municipal(false),
        );
    }

    public function testSpecialRegimeCannotBypassTheNationalCrossFieldPolicy(): void
    {
        $this->expectException(FiscalIssuanceBlocked::class);
        $this->expectExceptionMessage('NFSE-REGIME-INVALID');

        self::preflight('2026-09-30')->assertReady(
            self::invoice(),
            self::dps(['opcaoSimplesNacional' => 2, 'regimeEspecialTributacao' => 3]),
            'group-a',
            self::municipal(),
        );
    }

    /** @param array<string,mixed> $changes */
    private static function dps(array $changes = []): DpsData
    {
        return new DpsData(...array_replace([
            'cnpjPrestador' => '11222333000181',
            'municipioIbge' => '3304557',
            'itemListaServico' => '0107',
            'codigoTributacaoNacional' => '010701',
            'valorServico' => '100.00',
            'aliquota' => '2.00',
            'discriminacao' => 'Synthetic service',
            'dataCompetencia' => '2026-11-02',
            'opcaoSimplesNacional' => 1,
            'regimeEspecialTributacao' => 0,
        ], $changes));
    }

    private static function invoice(): Invoice
    {
        // The isolated Akaunting test double does not declare a type field.
        // Avoid PHP 8.2+ dynamic-property deprecations in this fixture.
        $invoice = new class () extends Invoice {
            public string $type = 'invoice';
        };
        $invoice->id = 51;
        $invoice->company_id = 31;
        $invoice->issued_at = '2026-11-01';

        return $invoice;
    }

    /** @return array<string,mixed> */
    private static function municipal(bool $allowed = true): array
    {
        return [
            'can_attempt' => $allowed,
            'reason' => $allowed ? 'unverifiable' : 'official_municipal_rejection',
            'context' => ['incidence_municipality' => '3304557'],
            'municipal' => ['decision' => $allowed ? 'unverifiable' : 'rejected'],
        ];
    }

    private static function preflight(?string $eventDate): FinalFiscalAssessmentPreflight
    {
        $resolver = new InvoiceFiscalAssessmentContextResolver(
            groups: static fn (Invoice $invoice): array => [[
                'key' => 'group-a',
                'rtc_supply_category' => 'ordinary_lc116',
                'item_lista_servico' => '0107',
                'codigo_tributacao_nacional' => '010701',
                'amount' => '100.00',
                'items' => [['document_item_id' => 91, 'item_id' => 7]],
            ]],
            taxFacts: static fn (int $company, int $invoice, string $date, ?array $ids): array => [],
            eventEvidence: static fn (int $company, int $invoice, string $key): ?array =>
                $eventDate === null ? null : [
                    'date' => $eventDate,
                    'kind' => 'operator_attestation',
                    'reference' => 'Synthetic order SO-42',
                ],
        );

        return new FinalFiscalAssessmentPreflight(contexts: $resolver);
    }
}
