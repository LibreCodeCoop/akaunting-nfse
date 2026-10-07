<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Jobs;

use Modules\Nfse\Tests\TestCase;

final class ProcessNfsePostEmissionTest extends TestCase
{
    public function testJobUsesLaravelQueueContractAndSerializableConstructorPayload(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/Jobs/ProcessNfsePostEmission.php');

        self::assertStringContainsString('final class ProcessNfsePostEmission', $content);
        self::assertStringNotContainsString('ShouldQueue', $content);
        self::assertStringContainsString('public readonly int $invoiceId', $content);
        self::assertStringContainsString('public readonly int $receiptId', $content);
        self::assertStringContainsString('public readonly string $authorizedXml', $content);
        self::assertStringContainsString('public readonly ?array $email = null', $content);
        self::assertStringNotContainsString('NfseClientInterface', $content);
        self::assertStringNotContainsString('Request $request', $content);
        self::assertStringNotContainsString('Dispatchable', $content);
        self::assertStringNotContainsString('InteractsWithQueue', $content);
        self::assertStringNotContainsString('SerializesModels', $content);
        self::assertStringNotContainsString('Queueable', $content);
    }

    public function testJobStoresArtifactsBeforeSendingEmail(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/Jobs/ProcessNfsePostEmission.php');

        $artifactPosition = strpos($content, '$artifactStorage->store($invoice, $this->receiptData($receipt), $receipt);');
        $emailPosition = strpos($content, '$this->sendEmail(');

        self::assertNotFalse($artifactPosition);
        self::assertNotFalse($emailPosition);
        self::assertLessThan($emailPosition, $artifactPosition);
        self::assertStringContainsString('$artifactStorage = new NfseArtifactStorage();', $content);
        self::assertStringContainsString('new NfseIssued(', $content);
        self::assertStringContainsString("Notification::route('mail'", $content);
    }
}
