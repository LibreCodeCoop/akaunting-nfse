<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Models\Common\Contact;
use App\Models\Common\Item;
use App\Models\Document\Document;
use App\Models\Setting\Category;
use App\Traits\Permissions;
use Modules\Nfse\Models\AdnSyncDocument;
use Tests\Feature\FeatureTestCase;

final class AdnNativeApiTest extends FeatureTestCase
{
    use Permissions;

    public function testAllAdnRoutesRequireCorePermissions(): void
    {
        foreach ([
            'api.nfse.adn.review-options' => 'permission:read-purchases-bills',
            'api.nfse.adn.documents.index' => 'permission:read-purchases-bills',
            'api.nfse.adn.documents.preview' => 'permission:read-purchases-bills',
            'api.nfse.adn.documents.review-history' => 'permission:read-purchases-bills',
            'api.nfse.adn.distribution' => 'permission:read-nfse-settings',
            'api.nfse.adn.documents.ignore' => 'permission:update-purchases-bills',
            'api.nfse.adn.documents.import' => 'permission:create-purchases-bills',
            'api.nfse.invoices.adn-events' => 'permission:read-sales-invoices',
        ] as $name => $permission) {
            $route = app('router')->getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertContains('api', $route->gatherMiddleware());
            self::assertContains($permission, $route->gatherMiddleware());
        }

        foreach ([
            'nfse.adn.index' => 'permission:read-purchases-bills',
            'nfse.adn.review-options' => 'permission:read-purchases-bills',
            'nfse.adn.documents.index' => 'permission:read-purchases-bills',
            'nfse.adn.documents.preview' => 'permission:read-purchases-bills',
            'nfse.adn.documents.review-history' => 'permission:read-purchases-bills',
            'nfse.adn.distribution' => 'permission:read-nfse-settings',
            'nfse.adn.review.ignore' => 'permission:update-purchases-bills',
            'nfse.adn.review.import' => 'permission:create-purchases-bills',
            'nfse.invoices.adn-events' => 'permission:read-sales-invoices',
        ] as $name => $permission) {
            $route = app('router')->getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertContains($permission, $route->gatherMiddleware());
        }
    }

    public function testReviewListAndPreviewAreTenantScopedAndDoNotExposeXml(): void
    {
        $this->loginAs();
        $own = $this->document('nfse:local', (int) company_id());
        $foreign = $this->document('nfse:foreign', (int) company_id() + 999);

        $response = $this->get(route('nfse.adn.documents.index', ['limit' => 1]));
        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonMissing(['document_key' => $foreign->document_key]);
        $response->assertDontSee('Fornecedor Recebido Ltda');

        $listWithPreview = $this->get(route('nfse.adn.documents.index', [
            'include_preview' => '1',
            'review_status' => 'pending',
        ]));
        $listWithPreview->assertOk()
            ->assertJsonPath('data.0.accounting_preview.supplier_name', 'Fornecedor Recebido Ltda')
            ->assertJsonPath('data.0.accounting_preview.gross_value', '100.00');
        $listWithPreview->assertJsonMissingPath('data.0.xml');

        $preview = $this->get(route('nfse.adn.documents.preview', $own->id));
        $preview->assertOk()
            ->assertJsonPath('data.document_id', $own->id)
            ->assertJsonPath('data.accounting_preview.supplier_name', 'Fornecedor Recebido Ltda');

        $this->withExceptionHandling()
            ->get(route('nfse.adn.documents.preview', $foreign->id))
            ->assertNotFound();

        $this->withExceptionHandling()->get(route('nfse.adn.documents.review-history', $foreign->id))
            ->assertNotFound();

        self::assertSame('pending', $own->fresh()->review_status);
        self::assertNull($own->fresh()->imported_document_id);
    }

    public function testReceivedAdnDocumentExposesOnlyProvisionalIbsCbsCreditEvidence(): void
    {
        $this->loginAs();
        $document = $this->document('nfse:received-ibs-cbs', (int) company_id());
        $xml = (string) $document->xml;
        $document->xml = str_replace('</infNFSe>',
            '<IBSCBS><valores><gIBS><vIBSTot>1.20</vIBSTot></gIBS>'
            . '<gCBS><vCBS>0.00</vCBS></gCBS></valores></IBSCBS></infNFSe>',
            $xml);
        $document->save();

        $this->get(route('nfse.adn.documents.index', ['fiscal_role' => 'received']))
            ->assertOk()->assertJsonMissingPath('data.0.ibs_cbs_credit_candidate');

        $this->get(route('nfse.adn.documents.index', [
            'include_preview' => '1',
            'fiscal_role' => 'received',
        ]))->assertOk()
            ->assertJsonPath('data.0.ibs_cbs_credit_candidate.credit_eligibility', 'unverifiable')
            ->assertJsonPath('data.0.ibs_cbs_credit_candidate.ibs.amount', '1.20')
            ->assertJsonPath('data.0.ibs_cbs_credit_candidate.environment', 'sandbox')
            ->assertJsonPath('data.0.ibs_cbs_credit_candidate.cbs.status', 'reported_zero')
            ->assertJsonMissingPath('data.0.xml');

        $this->get(route('nfse.adn.documents.preview', $document->id))
            ->assertOk()
            ->assertJsonPath('data.ibs_cbs_credit_candidate.credit_eligibility', 'unverifiable')
            ->assertJsonPath('data.ibs_cbs_credit_candidate.source_sha256', hash('sha256', $document->xml));
        self::assertSame('pending', $document->fresh()->review_status);
        self::assertNull($document->fresh()->imported_document_id);
    }

    public function testIgnoredReceivedDocumentIsExcludedFromCreditReviewCandidates(): void
    {
        $this->loginAs();
        $document = $this->document('nfse:ignored-credit', (int) company_id());
        $document->review_status = 'ignored';
        $document->save();

        $this->get(route('nfse.adn.documents.preview', $document->id))
            ->assertOk()->assertJsonPath('data.ibs_cbs_credit_candidate', null);
        $this->get(route('nfse.adn.documents.index', ['include_preview' => '1']))
            ->assertOk()->assertJsonPath('data.0.ibs_cbs_credit_candidate', null);
    }

    public function testIntermediatedDocumentIsNeverClassifiedAsCreditCandidate(): void
    {
        $this->loginAs();
        $document = $this->document('nfse:intermediated-credit', (int) company_id());
        $document->fiscal_role = 'intermediated';
        $document->save();

        $this->get(route('nfse.adn.documents.preview', $document->id))
            ->assertOk()->assertJsonPath('data.ibs_cbs_credit_candidate', null);
    }

    public function testReadApiRejectsInvalidFiltersBeforeQuery(): void
    {
        $this->loginAs();
        foreach ([['limit' => '101'], ['limit' => '0'], ['limit' => ['1']], ['review_status' => 'unrecognized'], ['fiscal_role' => 'invalid'], ['include_preview' => 'true']] as $query) {
            $this->get(route('nfse.adn.documents.index', $query))->assertStatus(422);
        }
    }

    public function testReviewOptionsContainOnlyCurrentCompanyChoices(): void
    {
        $this->loginAs();
        $ownVendor = Contact::factory()->vendor()->enabled()->create([
            'company_id' => (int) company_id(),
        ]);
        $foreignVendor = Contact::factory()->vendor()->enabled()->create([
            'company_id' => (int) company_id(),
        ]);
        $foreignVendor->forceFill(['company_id' => (int) company_id() + 999])->saveQuietly();

        $response = $this->get(route('nfse.adn.review-options'));
        $response->assertOk();
        $ids = array_column($response->json('data.vendors'), 'id');
        self::assertContains((int) $ownVendor->id, $ids);
        self::assertNotContains((int) $foreignVendor->id, $ids);
        self::assertIsArray($response->json('data.categories'));
        self::assertIsArray($response->json('data.items'));
    }

    public function testNoPermissionOperatorCannotReadBrowserAdnDocuments(): void
    {
        $this->loginAs();
        $role = $this->createRole('adn-read-denied');
        $this->attachPermission($role, 'read-admin-panel');

        $operator = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()->loginAs($operator);
        $this->get(route('nfse.adn.documents.index'))->assertForbidden();
        $this->get(route('nfse.adn.review-options'))->assertForbidden();
    }

    public function testJsonImportIsIdempotentAndIgnoredStateCannotOverwriteImportedBill(): void
    {
        $this->loginAs();
        $vendor = Contact::factory()->vendor()->enabled()->create();
        $category = Category::factory()->expense()->create();
        $item = Item::factory()->enabled()->create();
        $document = $this->document('nfse:json-import', (int) company_id());

        $payload = [
            'contact_id' => $vendor->id,
            'category_id' => $category->id,
            'item_id' => $item->id,
            'issued_at' => '2026-10-01',
            'due_at' => '2026-10-31',
        ];
        $this->postJson(route('nfse.adn.review.import', $document->id), $payload)
            ->assertCreated()
            ->assertJsonPath('data.review_status', 'imported')
            ->assertJsonPath('data.already_imported', false);

        $billId = (int) $document->fresh()->imported_document_id;
        self::assertGreaterThan(0, $billId);
        $this->assertDatabaseHas('nfse_adn_review_events', [
            'company_id' => (int) company_id(),
            'adn_document_id' => (int) $document->id,
            'action' => 'imported',
            'bill_id' => $billId,
        ]);

        $this->postJson(route('nfse.adn.review.import', $document->id), $payload)
            ->assertOk()
            ->assertJsonPath('data.bill_id', $billId)
            ->assertJsonPath('data.already_imported', true);

        $this->postJson(route('nfse.adn.review.ignore', $document->id))
            ->assertStatus(409);
        self::assertSame('imported', $document->fresh()->review_status);
        $this->get(route('nfse.adn.documents.review-history', $document->id))
            ->assertOk()
            ->assertJsonPath('data.0.action', 'conflict')
            ->assertJsonPath('data.0.reason', 'already_imported')
            ->assertJsonPath('data.1.action', 'imported')
            ->assertJsonPath('meta.total', 2);
        self::assertSame(1, Document::query()->whereKey($billId)->where('type', Document::BILL_TYPE)->count());
    }

    public function testJsonIgnoreIsIdempotentAndPreservesOriginalReviewRecord(): void
    {
        $this->loginAs();
        $document = $this->document('nfse:json-ignore', (int) company_id());

        $this->postJson(route('nfse.adn.review.ignore', $document->id))
            ->assertOk()
            ->assertJsonPath('data.review_status', 'ignored');
        $ignoredAt = $document->fresh()->ignored_at;
        self::assertNotNull($ignoredAt);

        $this->postJson(route('nfse.adn.review.ignore', $document->id))
            ->assertOk()
            ->assertJsonPath('data.review_status', 'ignored');

        self::assertSame($ignoredAt->toISOString(), $document->fresh()->ignored_at->toISOString());
        $this->get(route('nfse.adn.documents.review-history', $document->id))
            ->assertOk()
            ->assertJsonPath('data.0.action', 'ignored')
            ->assertJsonPath('meta.total', 1);
        $this->postJson(route('nfse.adn.review.import', $document->id))
            ->assertStatus(409);
        $this->get(route('nfse.adn.documents.review-history', $document->id))
            ->assertJsonPath('data.0.reason', 'not_pending')
            ->assertJsonPath('meta.total', 2);
    }

    public function testInvalidAccountingXmlIsRejectedAndAuditedWithoutFinancialMutation(): void
    {
        $this->loginAs();
        $document = $this->document('nfse:invalid-xml', (int) company_id());
        $document->xml = '<not-xml';
        $document->save();

        $this->get(route('nfse.adn.documents.preview', $document->id))->assertStatus(422);
        $this->postJson(route('nfse.adn.review.import', $document->id))->assertStatus(422);
        $this->get(route('nfse.adn.documents.review-history', $document->id))
            ->assertJsonPath('data.0.action', 'rejected')
            ->assertJsonPath('data.0.reason', 'invalid_xml')
            ->assertJsonPath('meta.total', 1);
        self::assertNull($document->fresh()->imported_document_id);
        self::assertSame('pending', $document->fresh()->review_status);
    }

    public function testBillCreatePermissionCannotImplicitlyCreateVendor(): void
    {
        $this->loginAs();
        $category = Category::factory()->expense()->create();
        $item = Item::factory()->enabled()->create();
        $document = $this->document('nfse:no-vendor-permission', (int) company_id());

        $role = $this->createRole('adn-import-no-vendor-create');
        $this->attachPermission($role, 'read-admin-panel');
        $this->attachPermission($role, 'create-purchases-bills');
        $this->attachPermission($role, 'read-purchases-bills');

        $operator = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()->loginAs($operator)
            ->postJson(route('nfse.adn.review.import', $document->id), [
                'create_vendor' => true,
                'category_id' => $category->id,
                'item_id' => $item->id,
                'issued_at' => '2026-10-01',
                'due_at' => '2026-10-31',
            ])
            ->assertForbidden();

        self::assertSame('pending', $document->fresh()->review_status);
        self::assertNull($document->fresh()->imported_document_id);
    }

    private function document(string $key, int $companyId): AdnSyncDocument
    {
        return AdnSyncDocument::query()->create([
            'company_id' => $companyId,
            'environment' => 'sandbox',
            'document_key' => $key,
            'nsu' => 1,
            'chave_acesso' => str_repeat('1', 50),
            'tipo_documento' => 'NFSe',
            'xml' => '<NFSe><infNFSe><prest><CNPJ>11222333000181</CNPJ><xNome>Fornecedor Recebido Ltda</xNome></prest><DPS><infDPS><dCompet>2026-10-01</dCompet><serv><cServ><xDescServ>Consultoria</xDescServ></cServ></serv><valores><vServPrest><vServ>100.00</vServ></vServPrest></valores></infDPS></DPS><valores><vLiq>100.00</vLiq></valores></infNFSe></NFSe>',
            'recognized_event' => false,
            'fiscal_role' => 'received',
            'review_status' => 'pending',
        ]);
    }
}
