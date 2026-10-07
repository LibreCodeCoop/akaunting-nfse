<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Tests\TestCase;

final class PostEmissionQueueTest extends TestCase
{
    public function testDispatcherUsesLaravelQueueChainAndKeepsSyncCompatibilityDocumented(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/Application/PostEmissionDispatcher.php');

        self::assertStringContainsString('Bus::chain($jobs)->dispatch();', $content);
        self::assertStringContainsString('QUEUE_CONNECTION=sync', $content);
        self::assertStringContainsString('StoreIssuedNfseArtifacts', $content);
        self::assertStringContainsString('SendIssuedNfseEmail', $content);
    }

    public function testArtifactJobRetriesButEmailJobDoesNotRetryAutomatically(): void
    {
        $artifactJob = (string) file_get_contents(dirname(__DIR__, 3) . '/Jobs/StoreIssuedNfseArtifacts.php');
        $emailJob = (string) file_get_contents(dirname(__DIR__, 3) . '/Jobs/SendIssuedNfseEmail.php');

        self::assertStringContainsString('public int $tries = 3;', $artifactJob);
        self::assertStringContainsString('public array $backoff = [10, 30, 60];', $artifactJob);
        self::assertStringContainsString('public int $tries = 1;', $emailJob);
        self::assertStringContainsString('implements ShouldQueue', $artifactJob);
        self::assertStringContainsString('implements ShouldQueue', $emailJob);
    }

    public function testQueuedPayloadUsesReceiptIdentifiersInsteadOfEmbeddingAuthorizedXml(): void
    {
        $artifactJob = (string) file_get_contents(dirname(__DIR__, 3) . '/Jobs/StoreIssuedNfseArtifacts.php');
        $dispatcher = (string) file_get_contents(dirname(__DIR__, 3) . '/Application/PostEmissionDispatcher.php');

        self::assertStringContainsString('public readonly int $invoiceId', $artifactJob);
        self::assertStringContainsString('public readonly int $receiptId', $artifactJob);
        self::assertStringNotContainsString('authorizedXml', $artifactJob);
        self::assertStringNotContainsString('authorizedXml', $dispatcher);
    }

    public function testReceiptPersistenceKeepsAuthorizedXmlBeforePostEmissionDispatch(): void
    {
        $persistence = (string) file_get_contents(dirname(__DIR__, 3) . '/Application/ReceiptPersistence.php');
        $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/Http/Controllers/InvoiceController.php');

        self::assertStringContainsString("\$values['authorized_xml'] = \$receipt->rawXml;", $persistence);
        self::assertStringContainsString('$email = $this->preparePostEmitEmail($request, $invoice);', $controller);
        self::assertStringContainsString('$this->dispatchPostEmission(', $controller);
        self::assertStringContainsString('$this->markInvoiceSentAfterEmission($invoice);', $controller);
    }

    public function testArtifactStoreIsIdempotentByPersistedArtifactPaths(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/Application/IssuedNfseArtifactStore.php');

        self::assertStringContainsString("trim((string) \$receipt->xml_webdav_path) === ''", $content);
        self::assertStringContainsString("trim((string) \$receipt->danfse_webdav_path) === ''", $content);
        self::assertStringContainsString("\$receipt->update(['xml_webdav_path' => \$xmlPath]);", $content);
        self::assertStringContainsString("\$receipt->update(['danfse_webdav_path' => \$pdfPath]);", $content);
    }
}
