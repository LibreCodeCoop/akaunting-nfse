<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Nfse\Application\DpsOfficialReadOnlyLookup;
use Modules\Nfse\Support\FiscalClientContext;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\NfseClientInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use Tests\Feature\FeatureTestCase;

final class DpsReadinessApiTest extends FeatureTestCase
{
    public function testSessionAndNativeReadOnlyRoutesRequireFiscalSettingsReadPermission(): void
    {
        foreach ([
            'nfse.dps.readiness', 'nfse.dps.lookup',
            'api.nfse.dps.readiness', 'api.nfse.dps.lookup',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route);
            self::assertContains('GET', $route->methods());
            self::assertNotContains('POST', $route->methods());
            self::assertContains('permission:read-nfse-settings', $route->gatherMiddleware());
        }
    }

    public function testReadinessAndLookupFailClosedWithoutConfiguredAuthority(): void
    {
        $this->loginAs();
        $this->getJson(route('nfse.dps.readiness'))
            ->assertOk()->assertJsonPath('data.mechanically_ready', false)
            ->assertJsonPath('data.external_reconciliation', 'not_proven_by_this_endpoint');

        $this->getJson(route('nfse.dps.lookup', ['number' => '900000000000008']))
            ->assertStatus(409)->assertJsonPath('error', 'dps_authority_unavailable');

        foreach (['0', 'foo', '1234567890123456', '1/2'] as $number) {
            $this->getJson(route('nfse.dps.lookup', ['number' => $number]))
                ->assertStatus(422)->assertJsonPath('error', 'invalid_dps_number');
        }
    }

    public function testOfficialLookupOnlyUsesHeadAndGetAndAlwaysClosesCertificateContext(): void
    {
        $client = new class implements NfseClientInterface {
    public array $calls = [];
    public bool $exists = true;
    public bool $failure = false;
    public int $posts = 0;

    public function existsDps(string $idDps): bool
    {
        $this->calls[] = 'head';
        if ($this->failure) throw new \RuntimeException('Synthetic service unavailable');
        return $this->exists;
    }

    public function queryDps(string $idDps): string
    {
        $this->calls[] = 'get';
        return 'synthetic-access-key';
    }

    public function emit(DpsData $dps): ReceiptData
    {
        $this->posts++;
        throw new \LogicException('Read-only diagnostic must not transmit');
    }

    public function query(string $chaveAcesso): ReceiptData
    {
        throw new \LogicException('No receipt download expected');
    }

    public function cancel(string $chaveAcesso, string $motivo): bool
    {
        throw new \LogicException('Read-only diagnostic must not cancel');
    }

    public function getDanfse(string $chaveAcesso): string
    {
        throw new \LogicException('No binary download expected');
    }
        };
        $closed = false;
        $lookup = new DpsOfficialReadOnlyLookup(
            static function (int $companyId, bool $sandbox) use ($client, &$closed): FiscalClientContext {
                self::assertSame(1, $companyId);
                self::assertTrue($sandbox);
                return new FiscalClientContext($client, static function () use (&$closed): void {
                    $closed = true;
                });
            },
        );

        self::assertSame('found', $lookup->check(1, true, 'synthetic-dps-id'));
        self::assertSame(['head', 'get'], $client->calls);
        self::assertTrue($closed);

        $client->calls = [];
        $client->exists = false;
        self::assertSame('not_found', $lookup->check(1, true, 'synthetic-dps-id'));
        self::assertSame(['head'], $client->calls);

        $client->failure = true;
        self::assertSame('inconclusive', $lookup->check(1, true, 'synthetic-dps-id'));
        self::assertSame(0, $client->posts);
    }
}
