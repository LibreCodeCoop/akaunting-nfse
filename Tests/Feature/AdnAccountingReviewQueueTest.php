<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Modules\Nfse\Models\AdnSyncDocument;
use Tests\Feature\FeatureTestCase;

final class AdnAccountingReviewQueueTest extends FeatureTestCase
{
    public function testReceivedDocumentAppearsWithParsedAccountingPreview(): void
    {
        $this->loginAs();
        $document = $this->pendingDocument('review-1', str_repeat('1', 50));

        $this->get(route('nfse.adn.index'))
            ->assertOk()
            ->assertSee('Fornecedor Recebido Ltda')
            ->assertSee('2026-10-01')
            ->assertSee('100.00')
            ->assertSee((string) $document->chave_acesso);
    }

    public function testIgnoreKeepsTraceAndRemovesPendingState(): void
    {
        $this->loginAs();
        $document = $this->pendingDocument('review-2', str_repeat('2', 50));

        $this->post(route('nfse.adn.review.ignore', $document))
            ->assertRedirect(route('nfse.adn.index'));

        $fresh = $document->fresh();
        self::assertSame('ignored', $fresh->review_status);
        self::assertNotNull($fresh->ignored_at);
        self::assertSame(str_repeat('2', 50), $fresh->chave_acesso);
    }

    public function testIgnoreRejectsDocumentOwnedByAnotherCompany(): void
    {
        $this->loginAs();
        $document = $this->pendingDocument('review-3', str_repeat('3', 50));
        $document->company_id = ((int) company_id()) + 999;
        $document->save();

        $this->withExceptionHandling()
            ->post(route('nfse.adn.review.ignore', $document))
            ->assertNotFound();

        self::assertSame('pending', $document->fresh()->review_status);
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
