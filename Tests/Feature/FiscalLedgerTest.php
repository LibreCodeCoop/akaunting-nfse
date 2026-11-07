<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Models\Document\Document;
use App\Traits\Permissions;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Models\NfseReceiptPayload;
use Tests\Feature\FeatureTestCase;

final class FiscalLedgerTest extends FeatureTestCase
{
    use Permissions;
    public function testLedgerOnlyShowsReceiptsFromCurrentCompanyIncludingMultipleForOneInvoice(): void
    {
        $this->loginAs();

        $currentCompanyId = (int) company_id();
        $invoice = Document::factory()->invoice()->create(['company_id' => $currentCompanyId]);
        $otherInvoice = Document::factory()->invoice()->create(['company_id' => $currentCompanyId]);
        $otherInvoice->forceFill(['company_id' => $currentCompanyId + 1000])->saveQuietly();

        $original = $this->receipt($invoice, '81001', 'cancelled');
        $replacement = $this->receipt($invoice, '81002', 'emitted');
        $foreign = $this->receipt($otherInvoice, '81003', 'emitted');

        $response = $this->get(route('nfse.ledger.data'));

        $response->assertOk();
        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        self::assertContains($original->id, $ids);
        self::assertContains($replacement->id, $ids);
        self::assertNotContains($foreign->id, $ids);
    }

    public function testLedgerFiltersFiscalStatusBeforePagination(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $cancelled = $this->receipt($invoice, '82001', 'cancelled');
        $emitted = $this->receipt($invoice, '82002', 'emitted');

        $response = $this->get(route('nfse.ledger.data', ['status' => 'cancelled']));

        $response->assertOk();
        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        self::assertContains($cancelled->id, $ids);
        self::assertNotContains($emitted->id, $ids);
    }

    public function testLedgerCombinesFiscalStatusAndReceiptNumberSearch(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $match = $this->receipt($invoice, '85001', 'emitted');
        $this->receipt($invoice, '85002', 'emitted');
        $this->receipt($invoice, '85001-OLD', 'cancelled');

        $response = $this->get(route('nfse.ledger.data', [
            'status' => 'emitted',
            'search' => '85001',
        ]));

        $response->assertOk();
        $response->assertOk();
        self::assertSame([$match->id], array_column($response->json('data'), 'id'));
    }

    public function testLedgerDownloadsXmlFromSelectedReceiptInsteadOfLatestInvoiceReceipt(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $original = $this->receipt($invoice, '83001', 'emitted');
        $this->receipt($invoice, '83002', 'emitted');

        NfseReceiptPayload::query()->create([
            'receipt_id' => $original->id,
            'authorized_xml' => '<nfse>original-receipt</nfse>',
        ]);

        $response = $this->get(route('nfse.ledger.artifacts.download', [
            'receipt' => $original->id,
            'artifact' => 'xml',
        ]));

        $response->assertOk();
        self::assertSame('<nfse>original-receipt</nfse>', $response->getContent());
    }

    public function testLedgerDownloadsDanfseForSelectedReceiptNotNewerReceipt(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $selected = $this->receipt($invoice, '83011', 'emitted');
        $newer = $this->receipt($invoice, '83012', 'emitted');

        $fixture = file_get_contents(__DIR__ . '/../../tests/fixtures/nfse_exemplo.xml');
        self::assertIsString($fixture);

        NfseReceiptPayload::query()->create([
            'receipt_id' => $selected->id,
            'authorized_xml' => $fixture,
        ]);
        // The newer receipt has deliberately no authorized XML. An incorrect
        // latest-by-invoice lookup would fail to generate the selected PDF.
        $response = $this->get(route('nfse.ledger.artifacts.download', [
            'receipt' => $selected->id,
            'artifact' => 'danfse',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename="nfse-83011.pdf"');
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
        self::assertGreaterThan(100, strlen((string) $response->getContent()));
        self::assertNotSame($selected->id, $newer->id);
    }

    public function testLedgerRejectsForeignCompanyReceiptArtifact(): void
    {
        $this->loginAs();

        $otherInvoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $otherInvoice->forceFill(['company_id' => (int) company_id() + 1000])->saveQuietly();
        $receipt = $this->receipt($otherInvoice, '84001', 'emitted');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->get(route('nfse.ledger.artifacts.download', [
            'receipt' => $receipt->id,
            'artifact' => 'xml',
        ]));
    }

    public function testLedgerFiltersByIssueDateRange(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $inside = $this->receipt($invoice, '86001', 'emitted');
        $outside = $this->receipt($invoice, '86002', 'emitted');
        $inside->forceFill(['data_emissao' => '2026-06-15 12:00:00'])->save();
        $outside->forceFill(['data_emissao' => '2026-07-15 12:00:00'])->save();

        $response = $this->get(route('nfse.ledger.data', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
        ]));

        $response->assertOk();
        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        self::assertContains($inside->id, $ids);
        self::assertNotContains($outside->id, $ids);
    }

    public function testLedgerSearchesAccessKeyWithinSelectedFiscalStatus(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $matched = $this->receipt($invoice, '90001', 'emitted');
        $differentStatus = $this->receipt($invoice, '90002', 'cancelled');
        $unrelated = $this->receipt($invoice, '90003', 'emitted');

        $matched->forceFill(['chave_acesso' => str_repeat('7', 50)])->save();
        $differentStatus->forceFill(['chave_acesso' => str_repeat('7', 50)])->save();
        $unrelated->forceFill(['chave_acesso' => str_repeat('8', 50)])->save();

        $response = $this->get(route('nfse.ledger.data', [
            'status' => 'emitted',
            'search' => str_repeat('7', 20),
        ]));

        $response->assertOk();
        $response->assertOk();
        self::assertSame(1, $response->json('meta.total'));
        self::assertSame([$matched->id], array_column($response->json('data'), 'id'));
    }

    public function testLedgerPaginationTotalsAreComputedAfterStatusFilter(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        for ($i = 0; $i < 27; ++$i) {
            $this->receipt($invoice, '93000-' . $i, 'emitted');
        }
        $this->receipt($invoice, '94000', 'cancelled');

        $response = $this->get(route('nfse.ledger.data', ['status' => 'emitted', 'search' => '93000-', 'page' => 2]));
        $response->assertOk();
        $response->assertOk()
            ->assertJsonPath('meta.total', 27)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(2, 'data');
    }

    public function testLedgerSearchesCustomerWithinCompanyAndFiscalStatus(): void
    {
        $this->loginAs();

        $matchInvoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $otherInvoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $matchInvoice->contact->forceFill(['name' => 'Ledger Customer ZXQ729'])->saveQuietly();
        // Invoice factories can reuse the same contact. Make the negative case
        // independent, so changing its name cannot overwrite the search target.
        $otherInvoice->forceFill(['contact_id' => \App\Models\Common\Contact::factory()->customer()->create(['company_id' => company_id(), 'name' => 'Other Ledger Customer'])->id])->saveQuietly();

        $match = $this->receipt($matchInvoice, '95001', 'emitted');
        $this->receipt($matchInvoice, '95002', 'cancelled');
        $this->receipt($otherInvoice, '95003', 'emitted');

        $response = $this->get(route('nfse.ledger.data', [
            'status' => 'emitted',
            'search' => 'ZXQ729',
        ]));

        $response->assertOk();
        $response->assertOk();
        self::assertSame(1, $response->json('meta.total'));
        self::assertSame([$match->id], array_column($response->json('data'), 'id'));
    }


    public function testLedgerAndArtifactDownloadRequireSalesInvoiceReadPermission(): void
    {
        $this->loginAs();

        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);
        $receipt = $this->receipt($invoice, '96001', 'emitted');

        $role = $this->createRole('nfse-ledger-restricted');
        $this->attachPermission($role, 'read-admin-panel');

        $restrictedUser = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()
            ->loginAs($restrictedUser)
            ->get(route('nfse.ledger.index'))
            ->assertForbidden();

        $this->withExceptionHandling()
            ->loginAs($restrictedUser)
            ->get(route('nfse.ledger.artifacts.download', [
                'receipt' => $receipt->id,
                'artifact' => 'xml',
            ]))
            ->assertForbidden();
    }

    public function testLedgerListsUnlinkedHistoricalReceiptsFromCurrentCompanyOnly(): void
    {
        $this->loginAs();

        $companyId = (int) company_id();
        $historical = NfseReceipt::query()->create([
            'invoice_id' => null,
            'company_id' => $companyId,
            'nfse_number' => '7',
            'data_emissao' => '2018-05-18 14:21:52',
            'status' => 'emitted',
            'archive_page_number' => 1,
            'archive_pdf_sha256' => str_repeat('a', 64),
            'archive_evidence' => ['recipient_tax' => '00000000000272'],
            'danfse_webdav_path' => 'LibreCode/Financeiro/2018/NFS_EMITIDAS_2018.04.pdf',
        ]);
        $foreign = NfseReceipt::query()->create([
            'invoice_id' => null,
            'company_id' => $companyId + 1000,
            'nfse_number' => '8',
            'status' => 'emitted',
            'archive_page_number' => 2,
        ]);

        $response = $this->get(route('nfse.ledger.data', ['from' => '2018-01-01', 'to' => '2018-12-31']));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $historical->id)
            ->assertJsonPath('data.0.invoice_id', null)
            ->assertJsonPath('data.0.historical_unlinked', true)
            ->assertJsonPath('data.0.archive_page_number', 1)
            ->assertJsonPath('data.0.can_download_xml', false)
            ->assertJsonPath('data.0.can_download_danfse', true)
            ->assertJsonPath('data.0.customer', '00000000000272');
        self::assertNotContains($foreign->id, array_column($response->json('data'), 'id'));
    }

    public function testHistoricalReceiptCannotExposeAnotherCompanyArtifact(): void
    {
        $this->loginAs();

        $receipt = NfseReceipt::query()->create([
            'invoice_id' => null,
            'company_id' => (int) company_id() + 1000,
            'nfse_number' => '134',
            'status' => 'emitted',
            'danfse_webdav_path' => 'LibreCode/Financeiro/2020/NFS_EMITIDAS_2020.12.pdf',
        ]);

        $this->withExceptionHandling()->get(route('nfse.ledger.artifacts.download', [
            'receipt' => $receipt->id,
            'artifact' => 'danfse',
        ]))->assertNotFound();
    }

    public function testHistoricalReceiptsNeverOfferAnInventedXml(): void
    {
        $this->loginAs();

        $receipt = NfseReceipt::query()->create([
            'invoice_id' => null,
            'company_id' => (int) company_id(),
            'nfse_number' => '33',
            'status' => 'emitted',
            'archive_page_number' => 1,
            'danfse_webdav_path' => 'LibreCode/Financeiro/2019/NFS_EMITIDAS_2019.02.pdf',
        ]);

        $this->withExceptionHandling()->get(route('nfse.ledger.artifacts.download', [
            'receipt' => $receipt->id,
            'artifact' => 'xml',
        ]))->assertNotFound();
    }

    public function testHistoricalReceiptUsesVerifiedCustomerWithoutCrossCompanyDisclosure(): void
    {
        $this->loginAs();

        $companyId = (int) company_id();
        $customer = \App\Models\Common\Contact::factory()->customer()->create([
            'company_id' => $companyId,
            'name' => 'Fictitious Acme Archive Customer',
        ]);
        $otherCustomer = \App\Models\Common\Contact::factory()->customer()->create([
            'company_id' => $companyId + 1000,
            'name' => 'Foreign Customer Not Accessible',
        ]);

        $visible = NfseReceipt::query()->create([
            'invoice_id' => null,
            'company_id' => $companyId,
            'recipient_contact_id' => $customer->id,
            'nfse_number' => '20181',
            'data_emissao' => '2018-06-15 12:00:00',
            'status' => 'emitted',
            'archive_page_number' => 1,
            'archive_pdf_sha256' => str_repeat('b', 64),
            'danfse_webdav_path' => 'LibreCode/Archive/example.pdf',
            'archive_evidence' => ['recipient_name' => 'Fictitious Verified Recipient'],
        ]);
        NfseReceipt::query()->create([
            'invoice_id' => null,
            'company_id' => $companyId,
            'recipient_contact_id' => $otherCustomer->id,
            'nfse_number' => '20182',
            'data_emissao' => '2018-06-15 12:00:00',
            'status' => 'emitted',
            'archive_page_number' => 1,
            'archive_pdf_sha256' => str_repeat('c', 64),
            'danfse_webdav_path' => 'LibreCode/Archive/example.pdf',
            'archive_evidence' => ['recipient_name' => 'Undetermined Historical Client'],
        ]);

        $this->get(route('nfse.ledger.data', ['search' => 'Fictitious Acme Archive']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.customer', 'Fictitious Acme Archive Customer');

        $this->get(route('nfse.ledger.data', ['search' => 'Foreign Customer Not Accessible']))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->get(route('nfse.ledger.data', ['search' => '20182']))
            ->assertOk()
            ->assertJsonPath('data.0.customer', 'Undetermined Historical Client');
    }

    public function testIncompleteHistoricalArchiveDoesNotAdvertiseDownload(): void
    {
        $this->loginAs();

        NfseReceipt::query()->create([
            'invoice_id' => null,
            'company_id' => (int) company_id(),
            'nfse_number' => '20183',
            'status' => 'cancelled',
            'archive_page_number' => 1,
            'danfse_webdav_path' => 'LibreCode/Archive/example.pdf',
            'archive_evidence' => ['recipient_name' => 'Fictitious Customer'],
        ]);

        $this->get(route('nfse.ledger.data', ['search' => '20183']))
            ->assertOk()
            ->assertJsonPath('data.0.can_download_danfse', false);
    }

    private function receipt(Document $invoice, string $number, string $status): NfseReceipt
    {
        return NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => $number,
            'chave_acesso' => str_repeat(substr($number, -1), 50),
            'status' => $status,
        ]);
    }
}
