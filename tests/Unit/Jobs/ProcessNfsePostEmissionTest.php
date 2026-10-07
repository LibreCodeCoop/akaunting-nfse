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

        self::assertStringContainsString('final class ProcessNfsePostEmission implements ShouldQueue', $content);
        self::assertStringContainsString('public readonly int $invoiceId', $content);
        self::assertStringContainsString('public readonly int $receiptId', $content);
        self::assertStringContainsString('public readonly string $authorizedXml', $content);
        self::assertStringContainsString('public readonly ?array $email = null', $content);
        self::assertStringNotContainsString('NfseClientInterface', $content);
        self::assertStringNotContainsString('Request $request', $content);
    }

    public function testJobGeneratesArtifactsLocallyBeforeSendingEmail(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/Jobs/ProcessNfsePostEmission.php');

        $artifactPosition = strpos($content, '$this->storeArtifacts($invoice, $receipt);');
        $emailPosition = strpos($content, '$this->sendEmail($invoice, $receipt->fresh() ?? $receipt);');

        self::assertNotFalse($artifactPosition);
        self::assertNotFalse($emailPosition);
        self::assertLessThan($emailPosition, $artifactPosition);
        self::assertStringContainsString('(new DanfseGenerator())->generateFromXml($this->authorizedXml)', $content);
        self::assertStringContainsString('new NfseIssued(', $content);
        self::assertStringContainsString("Notification::route('mail'", $content);
    }

    public function testArtifactFailuresAreLoggedWithoutTurningAuthorizationIntoFailure(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/Jobs/ProcessNfsePostEmission.php');

        self::assertStringContainsString("logFailure('XML', $throwable)", $content);
        self::assertStringContainsString("logFailure('DANFSE', $throwable)", $content);
        self::assertStringContainsString("if ($updates !== [])", $content);
    }
}
