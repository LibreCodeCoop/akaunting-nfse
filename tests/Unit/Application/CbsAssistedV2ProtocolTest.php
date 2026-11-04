<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\CbsAssistedV2Protocol;
use PHPUnit\Framework\TestCase;

final class CbsAssistedV2ProtocolTest extends TestCase
{
    public function testAllConsultationsAreAsynchronousAndOffByDefaultInRestrictedEnvironment(): void
    {
        $api = new CbsAssistedV2Protocol();

        foreach (['debitos', 'creditos', 'pagamentos', 'recolhimentos'] as $kind) {
            self::assertSame([
                'method' => 'POST',
                'url' => 'https://api.receitafederal.gov.br/apuracao-cbs-prr/v2/' . $kind . '/12345678',
                'json' => ['urlRetorno' => ''],
            ], $api->begin($kind, '12345678'));
        }

        self::assertSame(
            'https://api.receitafederal.gov.br/apuracao-cbs/v2/creditos/12345678',
            $api->begin('creditos', '12345678', 'production')['url'],
        );
    }

    public function testOfficialStatusAndDownloadRequestsNeverExposeTokensOrSignedUrls(): void
    {
        $api = new CbsAssistedV2Protocol();
        self::assertSame([
            'method' => 'GET',
            'url' => 'https://api.receitafederal.gov.br/apuracao-cbs-prr/v2/situacao/abc.123_X',
        ], $api->status('abc.123_X'));
        self::assertSame([
            'method' => 'GET',
            'url' => 'https://api.receitafederal.gov.br/apuracao-cbs-prr/v2/download/abc.123_X',
        ], $api->downloadLink('abc.123_X'));
    }

    public function testStartAndStatusResponsesAreValidatedWithoutGuessingSuccess(): void
    {
        $api = new CbsAssistedV2Protocol();
        self::assertSame(['ticket' => 'abc-123', 'estimated_seconds' => 120], $api->accepted(
            201, ['tiqueteSolicitacao' => 'abc-123', 'tEASegundos' => 120],
        ));
        self::assertSame(['ticket' => 'abc-123', 'estimated_seconds' => null], $api->accepted(
            201, ['tiqueteSolicitacao' => 'abc-123', 'tEASegundos' => -1],
        ));
        self::assertSame('EM_PROCESSAMENTO', $api->statusFromResponse(200, ['situacao' => 'EM_PROCESSAMENTO']));
        self::assertSame('CONCLUIDA', $api->statusFromResponse(200, ['situacao' => 'CONCLUIDA']));
    }

    public function testDisallowedParametersFailBeforeAnyNetworkRequest(): void
    {
        $api = new CbsAssistedV2Protocol();
        foreach ([
            fn () => $api->begin('resgates', '12345678'),
            fn () => $api->begin('debitos', '12345678000199'),
            fn () => $api->begin('debitos', '12345678', 'local'),
            fn () => $api->status('../situacao/outro'),
            fn () => $api->downloadLink('a?x=1'),
            fn () => $api->accepted(200, ['tiqueteSolicitacao' => 'abc']),
            fn () => $api->accepted(201, ['tiqueteSolicitacao' => 'abc', 'tEASegundos' => '2']),
            fn () => $api->statusFromResponse(200, ['situacao' => 'ASSINADA']),
        ] as $act) {
            try {
                $act();
                self::fail('The illegal API operation must be rejected.');
            } catch (\InvalidArgumentException | \DomainException) {
                self::assertTrue(true);
            }
        }
    }
}
