<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Contact;
use App\Models\Common\Item;
use App\Models\Document\Document;
use App\Models\Setting\Category;
use Modules\Nfse\Models\AdnSyncDocument;
use Tests\Feature\FeatureTestCase;

final class AdnDraftBillImportTest extends FeatureTestCase
{
    public function testReviewedReceivedNfseCreatesDraftBillAndPreservesSourceLink(): void
    {
        $this->loginAs();

        $vendor = Contact::factory()->vendor()->enabled()->create();
        $category = Category::factory()->expense()->create();
        $item = Item::factory()->enabled()->create();
        $document = $this->pendingDocument('import-1', str_repeat('4', 50));

        $response = $this->post(route('nfse.adn.review.import', $document->id), [
            'contact_id' => $vendor->id,
            'category_id' => $category->id,
            'item_id' => $item->id,
            'issued_at' => '2026-10-01',
            'due_at' => '2026-10-31',
        ]);

        $fresh = $document->fresh();
        self::assertSame('imported', $fresh->review_status);
        self::assertNotNull($fresh->imported_document_id);

        $bill = Document::query()->findOrFail($fresh->imported_document_id);
        self::assertSame(Document::BILL_TYPE, $bill->type);
        self::assertSame('draft', $bill->status);
        self::assertSame($vendor->id, $bill->contact_id);
        self::assertSame($category->id, $bill->category_id);
        self::assertSame(100.0, (float) $bill->amount);
        self::assertStringContainsString(str_repeat('4', 50), (string) $bill->notes);

        $response->assertRedirect(route('bills.show', $bill->id));
    }

    public function testImportCanExplicitlyCreateVendorFromAuthorizedXml(): void
    {
        $this->loginAs();

        $category = Category::factory()->expense()->create();
        $item = Item::factory()->enabled()->create();
        $document = $this->pendingDocument('import-2', str_repeat('5', 50));

        $this->post(route('nfse.adn.review.import', $document->id), [
            'contact_id' => 0,
            'create_vendor' => '1',
            'category_id' => $category->id,
            'item_id' => $item->id,
            'issued_at' => '2026-10-01',
            'due_at' => '2026-10-31',
        ])->assertRedirect();

        $vendor = Contact::query()
            ->vendor()
            ->where('tax_number', '11222333000181')
            ->firstOrFail();

        self::assertSame('Fornecedor Recebido Ltda', $vendor->name);
        self::assertSame($vendor->id, Document::query()->findOrFail($document->fresh()->imported_document_id)->contact_id);
    }

    public function testRepeatedImportDoesNotCreateDuplicateBill(): void
    {
        $this->loginAs();

        $vendor = Contact::factory()->vendor()->enabled()->create();
        $category = Category::factory()->expense()->create();
        $item = Item::factory()->enabled()->create();
        $document = $this->pendingDocument('import-3', str_repeat('6', 50));

        $payload = [
            'contact_id' => $vendor->id,
            'category_id' => $category->id,
            'item_id' => $item->id,
            'issued_at' => '2026-10-01',
            'due_at' => '2026-10-31',
        ];

        $this->post(route('nfse.adn.review.import', $document->id), $payload)->assertRedirect();
        $billId = (int) $document->fresh()->imported_document_id;

        $this->post(route('nfse.adn.review.import', $document->id), $payload)
            ->assertRedirect(route('bills.show', $billId));

        self::assertSame(
            1,
            Document::query()->whereKey($billId)->where('type', Document::BILL_TYPE)->count(),
        );
    }

    private function pendingDocument(string $key, string $accessKey): AdnSyncDocument
    {
        return AdnSyncDocument::query()->create([
            'company_id' => company_id(),
            'environment' => 'sandbox',
            'document_key' => 'nfse:' . $key,
            'nsu' => 1,
            'chave_acesso' => $accessKey,
            'tipo_documento' => 'NFSe',
            'xml' => '<NFSe><infNFSe><prest><CNPJ>11222333000181</CNPJ><xNome>Fornecedor Recebido Ltda</xNome></prest><DPS><infDPS><dCompet>2026-10-01</dCompet><serv><cServ><xDescServ>Consultoria recebida</xDescServ></cServ></serv><valores><vServPrest><vServ>100.00</vServ></vServPrest></valores></infDPS></DPS><valores><vLiq>100.00</vLiq></valores></infNFSe></NFSe>',
            'recognized_event' => false,
            'fiscal_role' => 'received',
            'review_status' => 'pending',
        ]);
    }
}
