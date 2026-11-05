<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use App\Models\Document\Document as Invoice;
use DateTimeImmutable;
use Modules\Nfse\Support\InvoiceFiscalAssessmentContextResolver;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use PHPUnit\Framework\TestCase;

final class InvoiceFiscalAssessmentContextResolverTest extends TestCase
{
    public function testAttestedEventDateRemainsDistinctFromInvoiceAndDpsCompetence(): void
    {
        $resolver = self::resolver([
            'date' => '2026-09-30',
            'kind' => 'operator_attestation',
            'reference' => 'Synthetic service order SO-42',
        ]);
        $context = $resolver->resolve(self::invoice(), self::dps(), 'group-a');

        self::assertSame('2026-09-30', $context->taxableEventDate?->format('Y-m-d'));
        self::assertSame('2026-11-01', $context->commercialDate?->format('Y-m-d'));
        self::assertSame('2026-11-02', $context->competenceDate?->format('Y-m-d'));
        self::assertSame('operator_attestation', $context->taxableEventSource);
        self::assertSame('akaunting.documents.issued_at', $context->commercialDateSource);
        self::assertSame('dps.dataCompetencia', $context->competenceSource);
        self::assertSame(31, $context->companyId);
        self::assertSame('group-a', $context->fiscalGroupKey);
    }

    public function testUnknownEventIsNeverInferredFromInvoiceDate(): void
    {
        $context = self::resolver(null)->resolve(self::invoice(), self::dps(), 'group-a');

        self::assertNull($context->taxableEventDate);
        self::assertNull($context->taxableEventSource);
        self::assertSame('2026-11-01', $context->commercialDate?->format('Y-m-d'));
    }

    public function testChangedFiscalGroupCannotReuseOldDps(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('NFSE-FISCAL-GROUP-CHANGED');
        self::resolver(null)->resolve(self::invoice(), self::dps(['valorServico' => '101.00']), 'group-a');
    }

    public function testInvalidFinalServiceAmountCannotBeTreatedAsConfirmed(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('NFSE-FISCAL-AMOUNT-UNVERIFIABLE');
        self::resolver(null)->resolve(self::invoice(), self::dps(['valorServico' => 'not-a-money-value']), 'group-a');
    }

    public function testUnknownGroupCannotBeSubstitutedWithAnotherGroup(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('NFSE-FISCAL-GROUP-UNVERIFIABLE');
        self::resolver(null)->resolve(self::invoice(), self::dps(), 'group-b');
    }

    public function testTwoGroupsUseIndependentDatesCategoriesAndItemTaxSelections(): void
    {
        $selected = [];
        $resolver = new InvoiceFiscalAssessmentContextResolver(
            groups: static fn (Invoice $invoice): array => [
                [
                    'key' => 'group-a', 'rtc_supply_category' => 'ordinary_lc116',
                    'item_lista_servico' => '0107', 'codigo_tributacao_nacional' => '010701',
                    'amount' => '100.00', 'items' => [['document_item_id' => 91]],
                ],
                [
                    'key' => 'group-b', 'rtc_supply_category' => 'lease',
                    'item_lista_servico' => '0103', 'codigo_tributacao_nacional' => '010301',
                    'amount' => '200.00', 'items' => [['document_item_id' => 92]],
                ],
            ],
            taxFacts: static function (int $company, int $invoice, string $date, ?array $ids) use (&$selected): array {
                $selected[] = $ids;

                return [];
            },
            eventEvidence: static fn (int $company, int $invoice, string $key): ?array => [
                'date' => $key === 'group-a' ? '2026-09-30' : '2026-11-01',
                'kind' => 'operator_attestation',
                'reference' => 'Synthetic evidence for ' . $key,
            ],
        );

        $first = $resolver->resolve(self::invoice(), self::dps(), 'group-a');
        $second = $resolver->resolve(
            self::invoice(),
            self::dps([
                'itemListaServico' => '0103',
                'codigoTributacaoNacional' => '010301',
                'valorServico' => '200.00',
            ]),
            'group-b',
        );

        self::assertSame([[91], [92]], $selected);
        self::assertSame('ordinary_lc116', $first->operationCategory);
        self::assertSame('lease', $second->operationCategory);
        self::assertSame('2026-09-30', $first->taxableEventDate?->format('Y-m-d'));
        self::assertSame('2026-11-01', $second->taxableEventDate?->format('Y-m-d'));
        self::assertSame('not_applicable', (new \Modules\Nfse\Application\FiscalAssessmentComposer())->assess($first)->decisions[0]->status);
        self::assertSame('unsupported', (new \Modules\Nfse\Application\FiscalAssessmentComposer())->assess($second)->decisions[1]->status);
    }

    /** @param array<string,string>|null $event */
    private static function resolver(?array $event): InvoiceFiscalAssessmentContextResolver
    {
        return new InvoiceFiscalAssessmentContextResolver(
            groups: static fn (Invoice $invoice): array => [[
                'key' => 'group-a',
                'rtc_supply_category' => 'ordinary_lc116',
                'item_lista_servico' => '0107',
                'codigo_tributacao_nacional' => '010701',
                'codigo_tributacao_municipal' => '',
                'codigo_nbs' => '',
                'amount' => '100.00',
                'items' => [['document_item_id' => 91, 'item_id' => 7]],
            ]],
            taxFacts: static function (int $companyId, int $invoiceId, string $competence, ?array $ids): array {
                self::assertSame(31, $companyId);
                self::assertSame(51, $invoiceId);
                self::assertSame('2026-11-02', $competence);
                self::assertSame([91], $ids);

                return [];
            },
            eventEvidence: static function (int $companyId, int $invoiceId, string $groupKey) use ($event): ?array {
                self::assertSame([31, 51, 'group-a'], [$companyId, $invoiceId, $groupKey]);

                return $event;
            },
        );
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
        $invoice->issued_at = new DateTimeImmutable('2026-11-01');

        return $invoice;
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
        ], $changes));
    }
}
