<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\OfficialIssuanceRejection;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\IssuanceException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\NfseErrorCode;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\QueryException;
use PHPUnit\Framework\TestCase;

final class OfficialIssuanceRejectionTest extends TestCase
{
    public function testRecognizesExactOfficialE0312AndSanitizesPersonalFields(): void
    {
        $classified = (new OfficialIssuanceRejection())->fromException(
            new IssuanceException(
                'Official rejection',
                NfseErrorCode::IssuanceRejected,
                422,
                ['erros' => [[
                    'Codigo' => 'E0312',
                    'Descricao' => 'Municipio nao administra este codigo nesta competencia',
                    'Complemento' => 'CNPJ 11222333000181 email usuario@example.org',
                ]]],
            ),
        );

        self::assertSame('E0312', $classified['code'] ?? null);
        self::assertSame(422, $classified['http_status'] ?? null);
        self::assertStringContainsString('Municipio nao administra', $classified['message'] ?? '');
        self::assertStringNotContainsString('11222333000181', $classified['message'] ?? '');
        self::assertStringNotContainsString('usuario@example.org', $classified['message'] ?? '');
    }

    public function testDifferentOfficialRejectionCodeIsPreserved(): void
    {
        $classified = (new OfficialIssuanceRejection())->fromException(
            new IssuanceException('Rejected', NfseErrorCode::IssuanceRejected, 400, [
                'erros' => [['codigo' => 'E0040', 'descricao' => 'Declaracao invalida']],
            ]),
        );

        self::assertSame('E0040', $classified['code'] ?? null);
    }

    public function testQueryErrorsAndUnconfirmedHttpDoNotBecomeOfficialRejection(): void
    {
        $payload = ['erros' => [['codigo' => 'E0312', 'descricao' => 'Fake rejection']]];
        $classifier = new OfficialIssuanceRejection();

        self::assertNull($classifier->fromException(
            new QueryException('Query failed', NfseErrorCode::QueryFailed, 400, $payload),
        ));
        self::assertNull($classifier->fromException(
            new IssuanceException('Gateway failed', NfseErrorCode::IssuanceRejected, 503, $payload),
        ));
        self::assertNull($classifier->fromException(
            new IssuanceException('No structured message', NfseErrorCode::IssuanceRejected, 400, []),
        ));
    }

    public function testNeverPersistsRawXmlOrCertificateMaterial(): void
    {
        $classified = (new OfficialIssuanceRejection())->fromException(
            new IssuanceException('Rejected', NfseErrorCode::IssuanceRejected, 400, [
                'erros' => [['codigo' => 'E0312', 'descricao' => '<DPS>private</DPS>']],
            ]),
        );

        self::assertSame('[redacted-structured-content]', $classified['message'] ?? null);
    }
}
