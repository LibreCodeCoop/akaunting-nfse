<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Common\Company;
use App\Models\Document\Document;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\NfsePostEmissionCompanyContext;
use Tests\Feature\FeatureTestCase;

final class PostEmissionCompanyContextTest extends FeatureTestCase
{
    public function testQueuedOperationUsesReceiptOwnerAndRestoresWorkerCompany(): void
    {
        $this->loginAs();
        $first = $this->company;
        $first->makeCurrent();
        setting()->set(['nfse.webdav_url' => 'https://first.example.test']);
        setting()->save();

        $secondId = DB::table('companies')->insertGetId([
            'domain' => 'post-emission-second.example.test',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $second = Company::query()->findOrFail($secondId);
        $second->makeCurrent();
        setting()->set(['nfse.webdav_url' => 'https://second.example.test']);
        setting()->save();
        $invoice = Document::factory()->invoice()->draft()->create(['company_id' => $secondId]);
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => 'SYNTH-QUEUED',
            'chave_acesso' => str_repeat('7', 50),
            'status' => 'emitted',
        ]);

        $first->makeCurrent();
        $actual = (new NfsePostEmissionCompanyContext())->run(
            (int) $invoice->id,
            (int) $receipt->id,
            static fn (): array => [
                (int) Company::getCurrent()?->id,
                setting('nfse.webdav_url'),
            ],
        );

        self::assertSame([(int) $secondId, 'https://second.example.test'], $actual);
        self::assertSame((int) $first->id, (int) Company::getCurrent()?->id);
        self::assertSame('https://first.example.test', setting('nfse.webdav_url'));

        try {
            (new NfsePostEmissionCompanyContext())->run(
                (int) $invoice->id,
                (int) $receipt->id,
                static function (): void {
                    throw new \LogicException('Synthetic worker failure');
                },
            );
            self::fail('Expected synthetic failure');
        } catch (\LogicException $exception) {
            self::assertSame('Synthetic worker failure', $exception->getMessage());
        }

        self::assertSame((int) $first->id, (int) Company::getCurrent()?->id);
    }

    public function testMismatchedReceiptCannotActivateOtherCompanyContext(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->draft()->create();
        $receipt = NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => 'SYNTH-GUARD',
            'chave_acesso' => str_repeat('8', 50),
            'status' => 'emitted',
        ]);
        $owner = (int) Company::getCurrent()?->id;
        $called = false;

        try {
            (new NfsePostEmissionCompanyContext())->run(
                (int) $invoice->id + 1000,
                (int) $receipt->id,
                static function () use (&$called): void {
                    $called = true;
                },
            );
            self::fail('Expected mismatched operation rejection');
        } catch (\DomainException) {
            self::assertFalse($called);
            self::assertSame($owner, (int) Company::getCurrent()?->id);
        }
    }
}
