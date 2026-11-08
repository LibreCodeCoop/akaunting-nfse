<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Document\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Nfse\Application\InvoiceFiscalDecision;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class InvoiceFiscalDecisionTest extends FeatureTestCase
{
    public function testAppendOnlyDecisionAndReversalLeaveFinancialDocumentUntouched(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create([
            'company_id' => company_id(), 'status' => 'draft',
        ]);
        $old = DB::table('documents')->where('id', $invoice->id)->first();

        $this->postJson(route('nfse.invoices.fiscal-decision.store', $invoice->id), [
            'decision' => 'not_applicable',
            'reason' => 'tax_recovery',
            'justification' => 'Synthetic reimbursement, not consideration for a service.',
            'evidence_reference' => 'SYNTHETIC-EVIDENCE-1',
        ])->assertCreated()->assertJsonPath('data.decision', 'not_applicable');

        $this->getJson(route('nfse.invoices.fiscal-decision.show', $invoice->id))
            ->assertOk()->assertJsonPath('data.current.decision', 'not_applicable');

        $this->postJson(route('nfse.invoices.fiscal-decision.store', $invoice->id), [
            'decision' => 'reversed', 'reason' => 'reconsidered',
            'justification' => 'Reviewed the original transaction evidence.',
        ])->assertCreated();

        $history = (new InvoiceFiscalDecision())->history((int) company_id(), (int) $invoice->id);
        self::assertCount(2, $history);
        self::assertSame('reversed', $history[0]['decision']);
        self::assertSame('not_applicable', $history[1]['decision']);

        $new = DB::table('documents')->where('id', $invoice->id)->first();
        self::assertSame($old->status, $new->status);
        self::assertSame($old->amount, $new->amount);
    }

    public function testNotApplicableNeedsEvidenceAndValidReceiptCannotBeOverridden(): void
    {
        $this->loginAs();
        // Akaunting's FeatureTestCase disables exception handling by default.
        $this->withExceptionHandling();
        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $body = [
            'decision' => 'not_applicable', 'reason' => 'non_service_revenue',
            'justification' => 'Synthetic transfer with no service obligation.',
        ];

        $this->postJson(route('nfse.invoices.fiscal-decision.store', $invoice->id), $body)
            ->assertUnprocessable()->assertJsonValidationErrors(['decision']);

        NfseReceipt::query()->create([
            'invoice_id' => $invoice->id, 'status' => 'emitted',
            'nfse_number' => 'SYN-900', 'chave_acesso' => str_repeat('1', 50),
        ]);
        $body['evidence_reference'] = 'SYNTHETIC-TRANSFER-2026';
        $this->postJson(route('nfse.invoices.fiscal-decision.store', $invoice->id), $body)
            ->assertUnprocessable()->assertJsonValidationErrors(['decision']);

        self::assertSame(0, DB::table('nfse_invoice_decisions')
            ->where('invoice_id', $invoice->id)->count());
        self::assertSame(1, NfseReceipt::query()->where('invoice_id', $invoice->id)->count());
    }

    public function testCrossCompanyInvoiceIsNotAccessible(): void
    {
        $this->loginAs();
        // Akaunting's FeatureTestCase disables exception handling by default.
        $this->withExceptionHandling();
        $invoice = Document::factory()->invoice()->create([
            'company_id' => (int) company_id() + 999,
        ]);

        $this->getJson(route('nfse.invoices.fiscal-decision.show', $invoice->id))
            ->assertNotFound();
        $this->postJson(route('nfse.invoices.fiscal-decision.store', $invoice->id), [
            'decision' => 'do_not_issue',
            'reason' => 'operational_review',
            'justification' => 'This would cross company boundaries.',
        ])->assertNotFound();
    }

    public function testNativeApiAndWebExposeTheSameReadWriteAcl(): void
    {
        foreach (['show' => 'read-sales-invoices', 'store' => 'update-sales-invoices'] as $action => $permission) {
            $api = Route::getRoutes()->getByName('api.nfse.invoices.fiscal-decision.' . $action);
            $web = Route::getRoutes()->getByName('nfse.invoices.fiscal-decision.' . $action);
            self::assertNotNull($api, $action);
            self::assertNotNull($web, $action);
            self::assertContains('api', $api->gatherMiddleware());
            self::assertContains('permission:' . $permission, $api->gatherMiddleware());
            self::assertContains('permission:' . $permission, $web->gatherMiddleware());
            self::assertSame($web->getActionName(), $api->getActionName());
        }
    }

    public function testAnUnresolvedDpsAttemptCannotBeHiddenByNonIssuance(): void
    {
        $this->withExceptionHandling()->loginAs();

        foreach (['pending', 'ambiguous'] as $state) {
            $invoice = Document::factory()->invoice()->create(['company_id' => (int) company_id()]);
            NfseEmissionAttempt::query()->create([
                'company_id' => (int) company_id(),
                'invoice_id' => (int) $invoice->id,
                'dps_identifier' => str_repeat($state === 'pending' ? '1' : '2', 42),
                'environment' => 2,
                'attempt_number' => 1,
                'payload_fingerprint' => hash('sha256', 'synthetic-' . $state),
                'municipio_ibge' => '3303302',
                'codigo_tributacao_nacional' => '010701',
                'origin' => 'manual',
                'status' => $state,
                'started_at' => now(),
            ]);

            $this->postJson(route('nfse.invoices.fiscal-decision.store', $invoice->id), [
                'decision' => 'do_not_issue',
                'reason' => 'operational_review',
                'justification' => 'Synthetic attempt remains unresolved.',
            ])->assertUnprocessable()->assertJsonValidationErrors(['decision']);

            self::assertSame(0, DB::table('nfse_invoice_decisions')
                ->where('invoice_id', $invoice->id)->count());
        }
    }

    public function testNativeInvoiceRendersDecisionFormAndReviewSeparately(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $this->get(route('invoices.show', $invoice->id))
            ->assertOk()->assertSee('data-nfse-decision-form="true"', false);
    }
}
