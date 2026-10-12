<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use App\Models\Document\Document;
use Modules\Nfse\Contracts\FiscalTaxFact;
use Modules\Nfse\Contracts\FiscalTaxRole;
use Modules\Nfse\Support\ApproximateTaxSourceResolver;
use Modules\Nfse\Support\InvoiceFederalPayloadResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceFederalPayloadResolverTest extends TestCase
{
    public function testSourceConfirmedAmountsAndBaseAreKeptSeparateFromConfiguredRates(): void
    {
        $facts = [
            self::fact('pis', FiscalTaxRole::PROVIDER_DUE, 1650, 7, 701, 10000, '1.65'),
            self::fact('cofins', FiscalTaxRole::PROVIDER_DUE, 7600, 8, 702, 10000, '7.60'),
            self::fact('irrf', FiscalTaxRole::WITHHELD_BY_PAYER, 1500, 9, 703),
        ];
        $settings = [
            'nfse.federal_piscofins_situacao_tributaria' => '1',
            'nfse.federal_piscofins_tipo_retencao' => '0',
            'nfse.federal_piscofins_aliquota_pis' => '99.99',
            'nfse.federal_piscofins_aliquota_cofins' => '99.99',
            'nfse.federal_valor_irrf' => '50',
        ];

        $payload = self::resolver($facts, $settings)->resolve(self::invoice(), null, 9999.00);

        self::assertSame('100.00', $payload['federalPiscofinsBaseCalculo']);
        self::assertSame('1.65', $payload['federalPiscofinsAliquotaPis']);
        self::assertSame('16.50', $payload['federalPiscofinsValorPis']);
        self::assertSame('7.60', $payload['federalPiscofinsAliquotaCofins']);
        self::assertSame('76.00', $payload['federalPiscofinsValorCofins']);
        self::assertSame('15.00', $payload['federalValorIrrf']);
        self::assertSame('', $payload['federalValorCsll']);
        self::assertSame('unverifiable', $payload['federalTaxEvidence']['legal_incidence']);
        self::assertSame('', $payload['totalTributosPercentualFederal']);
    }

    public function testRatesFromSettingsNeverManufactureTaxesWhenNoPersistedFactsExist(): void
    {
        $payload = self::resolver([], [
            'nfse.federal_piscofins_situacao_tributaria' => '1',
            'nfse.federal_piscofins_aliquota_pis' => '1.65',
            'nfse.federal_piscofins_aliquota_cofins' => '7.60',
            'nfse.federal_valor_irrf' => '1.50',
        ])->resolve(self::invoice());

        self::assertSame('', $payload['federalPiscofinsValorPis']);
        self::assertSame('', $payload['federalPiscofinsValorCofins']);
        self::assertSame('', $payload['federalValorIrrf']);
        self::assertSame('', $payload['federalPiscofinsBaseCalculo']);
        self::assertSame('no_observations', $payload['federalTaxEvidence']['status']);
    }

    public function testNt007TypeFourUsesOnlyActuallyRetainedPisAndCofins(): void
    {
        $rows = [
            self::fact('pis', FiscalTaxRole::WITHHELD_BY_PAYER, 6500, 10, 100),
            self::fact('cofins', FiscalTaxRole::WITHHELD_BY_PAYER, 30000, 11, 101),
            self::fact('irrf', FiscalTaxRole::WITHHELD_BY_PAYER, 15000, 12, 102),
        ];

        $payload = self::resolver($rows, [
            'nfse.federal_piscofins_tipo_retencao' => '4',
            'nfse.federal_piscofins_situacao_tributaria' => '1',
        ])->resolve(self::invoice());

        self::assertSame('4', $payload['federalPiscofinsTipoRetencao']);
        self::assertSame('365.00', $payload['federalValorCsll']);
        self::assertSame('150.00', $payload['federalValorIrrf']);
        self::assertSame('', $payload['federalPiscofinsValorPis']);
        self::assertSame('', $payload['federalPiscofinsValorCofins']);
    }

    /**
     * @param list<FiscalTaxFact> $facts
     */
    #[DataProvider('unsupportedFacts')]
    public function testUnsupportedOrUnverifiableFactsCannotBecomeTransmissible(array $facts, array $settings, string $error): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($error);
        self::resolver($facts, $settings)->resolve(self::invoice());
    }

    /** @return iterable<string,array{list<FiscalTaxFact>,array<string,string>,string}> */
    public static function unsupportedFacts(): iterable
    {
        $due = FiscalTaxRole::PROVIDER_DUE;
        $ret = FiscalTaxRole::WITHHELD_BY_PAYER;
        $situation = ['nfse.federal_piscofins_situacao_tributaria' => '1'];

        yield 'unclassified tax observation is not inferred by name or rate' => [
            [new FiscalTaxFact(101, 7, 701, 'normal', 500)], $situation, 'Unclassified',
        ];
        yield 'federal taxable base is not invoice total' => [
            [self::fact('pis', $due, 500, 7, 701)], $situation, 'BASE-UNVERIFIABLE',
        ];
        yield 'own tax without proven PIS/COFINS status' => [
            [self::fact('pis', $due, 500, 7, 701, 10000, '5.00')], [], 'STATUS-UNVERIFIABLE',
        ];
        yield 'ambiguity in two fiscal rates of equal identity' => [
            [
                self::fact('pis', $due, 500, 7, 701, 10000, '5.00'),
                self::fact('pis', $due, 500, 8, 702, 10000, '5.00'),
            ], $situation, 'RATE-UNVERIFIABLE',
        ];
        yield 'withholding type cannot conceal real CSLL' => [
            [self::fact('csll', $ret, 10000, 7, 701)],
            ['nfse.federal_piscofins_tipo_retencao' => '4'], 'RETENTION-TYPE-CONFLICT',
        ];
        yield 'declared withholding without actual entry is unverifiable' => [
            [], ['nfse.federal_piscofins_tipo_retencao' => '4'], 'WITHHOLDING-UNVERIFIABLE',
        ];
        yield 'CP withholding cannot silently disappear' => [
            [self::fact('cp', $ret, 1100, 7, 701)], [], 'ROLE-UNSUPPORTED',
        ];
        yield 'provider due IRRF is not an actual payer withholding' => [
            [self::fact('irrf', $due, 1500, 7, 701)], [], 'ROLE-UNSUPPORTED',
        ];
    }

    public function testResolverReceivesRealCompanyInvoiceCompetenceAndSelectedItemIds(): void
    {
        $invoice = self::invoice();
        $ids = [100, 200];
        $seen = [];
        $resolver = new InvoiceFederalPayloadResolver(
            taxFactsResolver: static function (int $company, int $document, string $date, ?array $items) use (&$seen): array {
                $seen = [$company, $document, $date, $items];

                return [];
            },
        );
        $resolver->resolve($invoice, $ids, 10.00, null, '010701', '2026-10-12');

        self::assertSame([5, 42, '2026-10-12', $ids], $seen);
    }

    public function testDocumentedApproximateZeroUsesIndependentSourceAndNotFiscalRates(): void
    {
        $source = [
            'id' => 3,
            'company_id' => 5,
            'national_service_code' => '010701',
            'simples_option' => 1,
            'competence_from' => '2026-01-01',
            'competence_until' => '2026-12-31',
            'source_kind' => 'documented_zero',
            'source_name' => 'Synthetic verified source',
            'source_version' => 'synthetic-v1',
            'source_reference' => 'synthetic-source-proof',
            'federal_percent' => '0.00',
            'state_percent' => '0.00',
            'municipal_percent' => '0.00',
        ];
        $resolver = new InvoiceFederalPayloadResolver(
            taxFactsResolver: static fn (): array => [],
            approximateTaxSources: new ApproximateTaxSourceResolver(
                static fn (): ?array => $source,
            ),
        );
        $payload = $resolver->resolve(self::invoice(), null, null, null, '010701', '2026-10-12');

        self::assertSame(2, $payload['indicadorTributacao']);
        self::assertSame('0.00', $payload['totalTributosPercentualFederal']);
        self::assertSame('0.00', $payload['totalTributosPercentualMunicipal']);
        self::assertSame('synthetic-v1', $payload['approximateTaxEvidence']['source']['version']);
    }

    private static function invoice(): Document
    {
        $invoice = new Document();
        $invoice->id = 42;
        $invoice->company_id = 5;
        $invoice->issued_at = '2026-10-12 12:00:00';
        $invoice->amount = 100.00;
        $invoice->items = [];

        return $invoice;
    }

    /** @param list<FiscalTaxFact> $facts
     *  @param array<string,string> $settings
     */
    private static function resolver(array $facts, array $settings = []): InvoiceFederalPayloadResolver
    {
        return new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
            taxFactsResolver: static fn (): array => $facts,
        );
    }

    private static function fact(
        string $identity,
        FiscalTaxRole $role,
        int $amount,
        int $taxId,
        int $rowId,
        ?int $base = null,
        ?string $rate = null,
    ): FiscalTaxFact {
        return new FiscalTaxFact(
            documentItemId: 101,
            taxId: $taxId,
            documentItemTaxRowId: $rowId,
            accountingType: $role === FiscalTaxRole::WITHHELD_BY_PAYER ? 'withholding' : 'normal',
            amountCents: $amount,
            fiscalTaxCode: $identity,
            fiscalRole: $role,
            origin: 'synthetic.document_item_taxes',
            baseCents: $base,
            ratePercent: $rate,
        );
    }
}
