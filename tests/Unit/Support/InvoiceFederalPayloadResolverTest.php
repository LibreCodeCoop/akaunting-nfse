<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use App\Models\Document\Document;
use Modules\Nfse\Support\InvoiceFederalPayloadResolver;
use PHPUnit\Framework\TestCase;

final class InvoiceFederalPayloadResolverTest extends TestCase
{
    public function testConfiguredRatesProduceDeterministicPayload(): void
    {
        $settings = [
            'nfse.tributacao_federal_mode' => 'configured_rates',
            'nfse.federal_piscofins_situacao_tributaria' => '1',
            'nfse.federal_piscofins_tipo_retencao' => '0',
            'nfse.federal_piscofins_aliquota_pis' => '1.65',
            'nfse.federal_piscofins_aliquota_cofins' => '7.60',
            'nfse.opcao_simples_nacional' => 1,
        ];

        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
            taxRateResolver: static fn (int $taxId): ?float => null,
        );

        $invoice = new Document();
        $invoice->amount = 100.00;
        $invoice->items = $this->items([]);

        $payload = $resolver->resolve($invoice);

        self::assertSame('100.00', $payload['federalPiscofinsBaseCalculo']);
        self::assertSame('1.65', $payload['federalPiscofinsAliquotaPis']);
        self::assertSame('1.65', $payload['federalPiscofinsValorPis']);
        self::assertSame('7.60', $payload['federalPiscofinsAliquotaCofins']);
        self::assertSame('7.60', $payload['federalPiscofinsValorCofins']);
    }

    public function testSnapshotAndPayloadCanBeScopedToSelectedGroupItems(): void
    {
        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => match ($key) {
                'nfse.tributacao_federal_mode' => 'per_invoice_amounts',
                'nfse.federal_piscofins_situacao_tributaria' => '1',
                'nfse.opcao_simples_nacional' => 1,
                default => $default,
            },
            taxRateResolver: static fn (int $taxId): ?float => null,
        );

        $invoice = new Document();
        $invoice->amount = 300.00;
        $invoice->items = $this->items([
            [
                'id' => 101,
                'item_taxes' => [
                    ['name' => 'PIS', 'amount' => 10.00, 'rate' => 10.00],
                    ['name' => 'COFINS', 'amount' => 20.00, 'rate' => 20.00],
                ],
            ],
            [
                'id' => 102,
                'item_taxes' => [
                    ['name' => 'PIS', 'amount' => 30.00, 'rate' => 15.00],
                ],
            ],
        ]);

        $payload = $resolver->resolve($invoice, [101], 100.00);

        self::assertSame('10.00', $payload['federalPiscofinsValorPis']);
        self::assertSame('20.00', $payload['federalPiscofinsValorCofins']);
        self::assertSame('100.00', $payload['federalPiscofinsBaseCalculo']);
    }

    public function testWithholdingReducedDocumentAmountDoesNotReducePisCofinsBase(): void
    {
        $settings = [
            'nfse.tributacao_federal_mode' => 'per_invoice_amounts',
            'nfse.federal_piscofins_situacao_tributaria' => '1',
            'nfse.federal_piscofins_tipo_retencao' => '0',
            'nfse.opcao_simples_nacional' => 1,
        ];

        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
            taxRateResolver: static fn (int $taxId): ?float => null,
        );

        $invoice = new Document();
        $invoice->amount = 29877.75;
        $invoice->items = $this->items([
            [
                'id' => 37386,
                'total' => 31500.00,
                'item_taxes' => [
                    ['name' => 'PIS', 'amount' => 204.75, 'rate' => 0.65],
                    ['name' => 'COFINS', 'amount' => 945.00, 'rate' => 3.00],
                    ['name' => 'IRRF', 'amount' => 472.50, 'rate' => 1.50],
                ],
            ],
        ]);

        $payload = $resolver->resolve($invoice);

        self::assertSame(31500.00, $resolver->serviceAmount($invoice));
        self::assertSame('31500.00', $payload['federalPiscofinsBaseCalculo']);
        self::assertSame('0.65', $payload['federalPiscofinsAliquotaPis']);
        self::assertSame('204.75', $payload['federalPiscofinsValorPis']);
        self::assertSame('3.00', $payload['federalPiscofinsAliquotaCofins']);
        self::assertSame('945.00', $payload['federalPiscofinsValorCofins']);
    }

    public function testNt007ConsolidatesPisAndCofinsWhenCsllIsNotRetained(): void
    {
        $settings = [
            'nfse.tributacao_federal_mode' => 'per_invoice_amounts',
            'nfse.federal_piscofins_situacao_tributaria' => '1',
            'nfse.federal_piscofins_tipo_retencao' => '4',
        ];

        $invoice = new Document();
        $invoice->id = 2329;
        $invoice->company_id = 1;
        $invoice->amount = 29877.75;
        $invoice->items = $this->items([
            [
                'id' => 37386,
                'total' => 31500.00,
                'item_taxes' => [
                    ['name' => 'PIS', 'amount' => 204.75, 'rate' => 0.65],
                    ['name' => 'COFINS', 'amount' => 945.00, 'rate' => 3.00],
                    ['name' => 'IRRF', 'amount' => 472.50, 'rate' => 1.50],
                ],
            ],
        ]);

        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
            taxRateResolver: static fn (int $taxId): ?float => null,
            withholdingRowsResolver: static fn (Document $invoice, ?array $itemIds): array => [
                ['document_item_id' => 37386, 'name' => 'PIS', 'amount' => 204.75],
                ['document_item_id' => 37386, 'name' => 'COFINS', 'amount' => 945.00],
                ['document_item_id' => 37386, 'name' => 'IRRF', 'amount' => 472.50],
            ],
        );

        $payload = $resolver->resolve($invoice);
        self::assertSame('4', $payload['federalPiscofinsTipoRetencao']);
        self::assertSame('', $payload['federalValorCsll']);
        self::assertSame('', $payload['federalPiscofinsValorPis']);
        self::assertSame('', $payload['federalPiscofinsValorCofins']);
        self::assertSame('472.50', $payload['federalValorIrrf']);
    }

    public function testCsllBucketRemainsIndependentWhenPisAndCofinsAreAlsoRetained(): void
    {
        $settings = [
            'nfse.tributacao_federal_mode' => 'per_invoice_amounts',
            'nfse.federal_piscofins_situacao_tributaria' => '1',
            'nfse.federal_piscofins_tipo_retencao' => '3',
            'nfse.opcao_simples_nacional' => 1,
        ];

        $invoice = new Document();
        $invoice->amount = 1000.00;
        $invoice->items = $this->items([]);

        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
            taxRateResolver: static fn (int $taxId): ?float => null,
            withholdingRowsResolver: static fn (Document $invoice, ?array $ids): array => [
                ['name' => 'PIS', 'amount' => 6.50, 'tax_type' => 'withholding'],
                ['name' => 'COFINS', 'amount' => 30.00, 'tax_type' => 'withholding'],
                ['name' => 'CSLL', 'amount' => 10.00, 'tax_type' => 'withholding'],
            ],
        );

        $payload = $resolver->resolve($invoice);
        self::assertSame('3', $payload['federalPiscofinsTipoRetencao']);
        self::assertSame('10.00', $payload['federalValorCsll']);
    }

    public function testCsllIsNotSentWhenRetentionTypeExplicitlyDoesNotRetainCsll(): void
    {
        $settings = [
            'nfse.tributacao_federal_mode' => 'per_invoice_amounts',
            'nfse.federal_piscofins_situacao_tributaria' => '1',
            'nfse.federal_piscofins_tipo_retencao' => '4',
            'nfse.federal_piscofins_aliquota_pis' => '0.65',
            'nfse.federal_piscofins_aliquota_cofins' => '3.00',
            'nfse.federal_valor_csll' => '1.00',
        ];

        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
        );
        $invoice = new Document();
        $invoice->amount = 31500.00;
        $invoice->items = $this->items([]);

        $payload = $resolver->resolve($invoice);

        self::assertSame('4', $payload['federalPiscofinsTipoRetencao']);
        self::assertSame('', $payload['federalValorCsll']);
    }

    public function testMissingCsllDoesNotRewriteConfiguredRetentionType(): void
    {
        $settings = [
            'nfse.tributacao_federal_mode' => 'per_invoice_amounts',
            'nfse.federal_piscofins_situacao_tributaria' => '1',
            'nfse.federal_piscofins_tipo_retencao' => '3',
            'nfse.federal_piscofins_aliquota_pis' => '0.65',
            'nfse.federal_piscofins_aliquota_cofins' => '3.00',
            'nfse.federal_valor_csll' => '',
        ];

        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
        );
        $invoice = new Document();
        $invoice->amount = 1000.00;
        $invoice->items = $this->items([]);

        $payload = $resolver->resolve($invoice);

        self::assertSame('3', $payload['federalPiscofinsTipoRetencao']);
        self::assertSame('', $payload['federalValorCsll']);
    }

    public function testPisCofinsRetentionsDoNotPopulateCsll(): void
    {
        $settings = [
            'nfse.tributacao_federal_mode' => 'per_invoice_amounts',
            'nfse.federal_piscofins_situacao_tributaria' => '1',
            'nfse.federal_piscofins_tipo_retencao' => '4',
            'nfse.opcao_simples_nacional' => 1,
        ];

        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => $settings[$key] ?? $default,
            taxRateResolver: static fn (int $taxId): ?float => null,
        );

        $invoice = new Document();
        $invoice->amount = 29877.75;
        $invoice->items = $this->items([
            [
                'id' => 37386,
                'total' => 31500.00,
                'item_taxes' => [
                    ['name' => 'PIS', 'amount' => 204.75, 'rate' => 0.65],
                    ['name' => 'COFINS', 'amount' => 945.00, 'rate' => 3.00],
                    ['name' => 'IRRF', 'amount' => 472.50, 'rate' => 1.50],
                ],
            ],
        ]);

        $payload = $resolver->resolve($invoice);

        self::assertSame('4', $payload['federalPiscofinsTipoRetencao']);
        self::assertSame('', $payload['federalValorCsll']);
        self::assertSame('472.50', $payload['federalValorIrrf']);
        self::assertSame('', $payload['totalTributosPercentualFederal']);
    }

    public function testFederalTaxationDoesNotFabricateApproximateTaxPercentages(): void
    {
        $invoice = new Document();
        $invoice->amount = 1000.00;
        $invoice->items = $this->items([]);
        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => match ($key) {
                'nfse.federal_piscofins_situacao_tributaria' => '1',
                'nfse.federal_piscofins_aliquota_pis' => '1.65',
                'nfse.federal_piscofins_aliquota_cofins' => '7.60',
                default => $default,
            },
        );

        $payload = $resolver->resolve($invoice);

        self::assertSame('16.50', $payload['federalPiscofinsValorPis']);
        self::assertSame(0, $payload['indicadorTributacao']);
        self::assertSame('', $payload['totalTributosPercentualFederal']);
        self::assertSame('', $payload['totalTributosPercentualMunicipal']);
    }

    public function testDocumentedZeroSourceHasThreeExplicitZerosAndIdentity(): void
    {
        $invoice = new Document();
        $invoice->company_id = 5;
        $invoice->amount = 100.00;
        $invoice->items = $this->items([]);

        $resolver = new InvoiceFederalPayloadResolver(
            approximateTaxSources: new \Modules\Nfse\Support\ApproximateTaxSourceResolver(
                static fn (): ?array => self::approximateSource([
                    'source_kind' => 'documented_zero',
                    'federal_percent' => '0.00',
                    'state_percent' => '0.00',
                    'municipal_percent' => '0.00',
                ]),
            ),
        );

        $payload = $resolver->resolve($invoice, null, null, null, '010701', '2026-10-01');

        self::assertSame(2, $payload['indicadorTributacao']);
        self::assertSame('0.00', $payload['totalTributosPercentualFederal']);
        self::assertSame('0.00', $payload['totalTributosPercentualMunicipal']);
        self::assertSame('confirmed_zero', $payload['approximateTaxEvidence']['status']);
        self::assertSame('synthetic-v1', $payload['approximateTaxEvidence']['source']['version']);
    }

    public function testIncompleteSourceCannotInventStateTaxZero(): void
    {
        $invoice = new Document();
        $invoice->company_id = 5;
        $invoice->amount = 100.00;
        $invoice->items = $this->items([]);

        $resolver = new InvoiceFederalPayloadResolver(
            approximateTaxSources: new \Modules\Nfse\Support\ApproximateTaxSourceResolver(
                static fn (): ?array => self::approximateSource(['state_percent' => null]),
            ),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('valid two-decimal percentage');
        $resolver->resolve($invoice, null, null, null, '010701', '2026-10-01');
    }

    public function testDeprecatedPercentSettingsAndIssAreNotSources(): void
    {
        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => match ($key) {
                'nfse.opcao_simples_nacional' => 1,
                'nfse.tributos_fed_p' => '99.99',
                'nfse.tributos_est_p' => '99.99',
                'nfse.tributos_mun_p' => '99.99',
                default => $default,
            },
            taxRateResolver: static fn (int $taxId): ?float => null,
        );

        $invoice = new Document();
        $invoice->amount = 31500.00;
        $invoice->items = $this->items([]);

        $payload = $resolver->resolve($invoice, null, null, '2.00');

        self::assertSame(0, $payload['indicadorTributacao']);
        self::assertSame('', $payload['totalTributosPercentualMunicipal']);
        self::assertSame('', $payload['totalTributosPercentualFederal']);
        self::assertSame('unknown', $payload['approximateTaxEvidence']['status']);
    }

    public function testSourcedMunicipalBurdenDoesNotEqualEffectiveIssFallback(): void
    {
        $resolver = new InvoiceFederalPayloadResolver(
            approximateTaxSources: new \Modules\Nfse\Support\ApproximateTaxSourceResolver(
                static fn (): ?array => self::approximateSource(['municipal_percent' => '4.25']),
            ),
        );

        $invoice = new Document();
        $invoice->company_id = 5;
        $invoice->amount = 31500.00;
        $invoice->items = $this->items([]);

        $payload = $resolver->resolve($invoice, null, null, '2.00', '010701', '2026-10-01');

        self::assertSame(2, $payload['indicadorTributacao']);
        self::assertSame('4.25', $payload['totalTributosPercentualMunicipal']);
        self::assertSame('6.25', $payload['totalTributosPercentualFederal']);
        self::assertSame('sourced_estimate', $payload['approximateTaxEvidence']['status']);
        self::assertSame('synthetic-v1', $payload['approximateTaxEvidence']['source']['version']);
    }

    /** @param array<string,mixed> $overrides */
    private static function approximateSource(array $overrides): array
    {
        return array_replace([
            'id' => 3,
            'company_id' => 5,
            'national_service_code' => '010701',
            'simples_option' => 1,
            'competence_from' => '2026-01-01',
            'competence_until' => '2026-12-31',
            'source_kind' => 'independent_estimate',
            'source_name' => 'Synthetic verified source',
            'source_version' => 'synthetic-v1',
            'federal_percent' => '6.25',
            'state_percent' => '0.00',
            'municipal_percent' => '3.20',
        ], $overrides);
    }

    public function testRetentionTypeWithCsllNotRetainedDoesNotFallBackWhenCsllIsAbsent(): void
    {
        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => match ($key) {
                'nfse.federal_piscofins_situacao_tributaria' => '1',
                'nfse.federal_piscofins_tipo_retencao' => '4',
                default => $default,
            },
            taxRateResolver: static fn (int $taxId): ?float => null,
        );

        $invoice = new Document();
        $invoice->amount = 100.00;
        $invoice->items = $this->items([]);

        $payload = $resolver->resolve($invoice);

        self::assertSame('4', $payload['federalPiscofinsTipoRetencao']);
        self::assertSame('', $payload['federalValorCsll']);
    }

    public function testRetentionTypeRequiringCsllIsNotSilentlyRewrittenWhenValueIsMissing(): void
    {
        $resolver = new InvoiceFederalPayloadResolver(
            settingResolver: static fn (string $key, mixed $default): mixed => match ($key) {
                'nfse.federal_piscofins_situacao_tributaria' => '1',
                'nfse.federal_piscofins_tipo_retencao' => '3',
                default => $default,
            },
            taxRateResolver: static fn (int $taxId): ?float => null,
        );

        $invoice = new Document();
        $invoice->amount = 100.00;
        $invoice->items = $this->items([]);

        $payload = $resolver->resolve($invoice);

        self::assertSame('3', $payload['federalPiscofinsTipoRetencao']);
        self::assertSame('', $payload['federalValorCsll']);
    }
    /**
     * @param list<array<string,mixed>> $rows
     */
    private function items(array $rows): object
    {
        return new class ($rows) {
            /** @param list<array<string,mixed>> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            /** @return list<array<string,mixed>> */
            public function toArray(): array
            {
                return $this->rows;
            }
        };
    }

}
