<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Company;
use App\Models\Document\Document;
use Illuminate\Http\Request;
use Modules\Nfse\Application\CompetenceClosingReport;
use Modules\Nfse\Http\Controllers\ClosingController;
use Modules\Nfse\Models\NfseReceipt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\FeatureTestCase;

final class CompetenceClosingReportTest extends FeatureTestCase
{
    public function testClosingExcludesCancelledAndSubstitutedSourcesFromTotals(): void
    {
        $invoice = $this->invoiceWithAmount('100.00');

        $original = $this->receipt($invoice, 'substituted', '40.00', '1', '1', '2026-10-10');
        $replacement = $this->receipt($invoice, 'emitted', '100.00', '1', '2', '2026-10-10');
        $replacement->update(['replaces_receipt_id' => $original->id]);
        $this->receipt($invoice, 'cancelled', '90.00', '1', '1', '2026-10-10');

        $closing = (new CompetenceClosingReport())->build(
            NfseReceipt::query()->with('invoice')->where('invoice_id', $invoice->id)->get(),
        );

        self::assertSame(1, $closing['summary']['documents']['emitted']);
        self::assertSame(1, $closing['summary']['documents']['substituted']);
        self::assertSame(1, $closing['summary']['documents']['cancelled']);
        self::assertSame('100.00', $closing['summary']['gross_service_value']);
        self::assertSame('100.00', $closing['summary']['taxation']['taxable']);
        self::assertSame('100.00', $closing['summary']['iss']['retained']);
        self::assertSame(0, $closing['summary']['reconciliation_mismatches']);
    }

    public function testGroupedActiveReceiptsReconcileToInvoiceTotal(): void
    {
        $invoice = $this->invoiceWithAmount('150.00');
        $this->receipt($invoice, 'emitted', '50.00', '2', '1', '2026-10-05');
        $this->receipt($invoice, 'emitted', '100.00', '3', '1', '2026-10-20');

        $closing = (new CompetenceClosingReport())->build(
            NfseReceipt::query()->with('invoice')->where('invoice_id', $invoice->id)->get(),
        );

        self::assertSame('150.00', $closing['summary']['gross_service_value']);
        self::assertSame('50.00', $closing['summary']['taxation']['immunity']);
        self::assertSame('100.00', $closing['summary']['taxation']['export']);
        self::assertSame([], $closing['reconciliation']);
    }

    public function testMissingSnapshotIsExplicitlyUnresolvedInsteadOfEstimated(): void
    {
        $invoice = $this->invoiceWithAmount('100.00');
        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => '999',
            'chave_acesso' => str_repeat('9', 50),
            'status' => 'emitted',
            'competence_date' => '2026-10-10',
        ]);

        $closing = (new CompetenceClosingReport())->build(
            NfseReceipt::query()->with('invoice')->where('invoice_id', $invoice->id)->get(),
        );

        self::assertSame(1, $closing['summary']['unresolved_documents']);
        self::assertSame(1, $closing['summary']['reconciliation_mismatches']);
        self::assertSame('missing_authorized_snapshot', $closing['reconciliation'][0]['reason']);
    }

    public function testClosingRoutesFilterByCompetenceAndCsvHasStableContract(): void
    {
        $invoice = $this->invoiceWithAmount('100.00');
        $this->receipt($invoice, 'emitted', '100.00', '1', '1', '2026-10-10');
        $this->receipt($invoice, 'emitted', '200.00', '1', '1', '2026-09-30');

        $this->loginAs()
            ->get(route('nfse.closing.index', ['competence' => '2026-10']))
            ->assertOk()
            ->assertSee('100.00')
            ->assertDontSee('200.00');

        $response = $this->loginAs()
            ->get(route('nfse.closing.export', ['competence' => '2026-10']))
            ->assertOk();

        $csv = $response->streamedContent();
        self::assertStringContainsString(
            'access_key,nfse_number,invoice_number,customer,competence,status,gross_service_value,liquid_value,taxation_group,iss_retention,issqn_value,pis,cofins,irrf,social_security,csll,ibs_total,cbs_total,nfse_total,source_sha256,reconciliation_status',
            $csv,
        );
    }

    public function testClosingAndCsvExcludeForeignCompanyFiscalDocuments(): void
    {
        $this->loginAs();
        $owner = (int) company_id();
        $ownInvoice = $this->invoiceWithAmount('100.00');
        $otherInvoice = $this->invoiceWithAmount('900.00');
        $otherInvoice->forceFill(['company_id' => $owner + 1000])->saveQuietly();

        $ownReceipt = $this->receipt($ownInvoice, 'emitted', '100.00', '1', '1', '2026-10-10');
        $foreignReceipt = $this->receipt($otherInvoice, 'emitted', '900.00', '1', '1', '2026-10-10');

        $this->get(route('nfse.closing.index', ['competence' => '2026-10']))
            ->assertOk()
            ->assertViewHas('closing', static function (array $closing) use ($ownReceipt, $foreignReceipt): bool {
                $ids = array_column($closing['rows'], 'receipt_id');

                return in_array($ownReceipt->id, $ids, true)
                    && !in_array($foreignReceipt->id, $ids, true);
            });

        $csv = $this->get(route('nfse.closing.export', ['competence' => '2026-10']))
            ->assertOk()->streamedContent();
        self::assertStringContainsString($ownReceipt->chave_acesso, $csv);
        self::assertStringNotContainsString($foreignReceipt->chave_acesso, $csv);
    }

    public function testClosingFailsWithoutAnActiveCompany(): void
    {
        $this->loginAs();
        $invoice = $this->invoiceWithAmount('100.00');
        $this->receipt($invoice, 'emitted', '100.00', '1', '1', '2026-10-10');

        $current = Company::getCurrent();
        self::assertNotNull($current);
        app()->forgetInstance(Company::class);
        try {
            $this->expectException(HttpException::class);
            $this->expectExceptionCode(0);
            (new ClosingController())->index(
                Request::create('/nfse/closing?competence=2026-10', 'GET'),
                new CompetenceClosingReport(),
            );
        } finally {
            app()->instance(Company::class, $current);
        }
    }

    private function invoiceWithAmount(string $amount): Document
    {
        $invoice = Document::factory()->invoice()->create();
        $invoice->forceFill(['amount' => $amount])->saveQuietly();

        return $invoice->fresh();
    }

    private function receipt(
        Document $invoice,
        string $status,
        string $gross,
        string $taxation,
        string $retention,
        string $competence,
    ): NfseReceipt {
        return NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => (string) random_int(1000, 9999),
            'chave_acesso' => str_repeat((string) random_int(1, 8), 50),
            'status' => $status,
            'competence_date' => $competence,
            'authorized_fiscal_snapshot' => [
                'schema_version' => 1,
                'source' => 'authorized_xml',
                'source_sha256' => hash('sha256', $invoice->id . $status . $gross . $competence),
                'gross_service_value' => $gross,
                'liquid_value' => $gross,
                'issqn' => [
                    'taxation' => $taxation,
                    'retention_type' => $retention,
                    'value' => '2.00',
                ],
                'federal' => [
                    'pis' => '1.00',
                    'cofins' => '2.00',
                    'irrf' => null,
                    'social_security' => null,
                    'csll' => null,
                ],
                'ibs_cbs' => [
                    'ibs_total' => null,
                    'cbs_total' => null,
                    'nfse_total' => $gross,
                ],
            ],
        ]);
    }
}
