<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\IssqnPayloadResolver;
use PHPUnit\Framework\TestCase;

final class IssqnPayloadResolverTest extends TestCase
{
    public function testNormalTaxationKeepsDefaultRuntimeContract(): void
    {
        $payload = (new IssqnPayloadResolver())->resolve([]);

        self::assertSame(1, $payload['tributacaoIssqn']);
        self::assertSame(1, $payload['tipoRetencaoIss']);
        self::assertNull($payload['issqnTipoImunidade']);
        self::assertNull($payload['issqnTipoSuspensao']);
        self::assertFalse($payload['requiresSpecialRuntime']);
    }

    public function testExportAndImmunityOnlyExposeRelevantFields(): void
    {
        $export = (new IssqnPayloadResolver())->resolve([
            'tributacao_issqn' => 3,
            'issqn_pais_resultado' => 'us',
        ]);

        self::assertSame('US', $export['issqnPaisResultado']);
        self::assertTrue($export['requiresSpecialRuntime']);

        $immunity = (new IssqnPayloadResolver())->resolve([
            'tributacao_issqn' => 2,
            'issqn_tipo_imunidade' => '4',
            'issqn_numero_processo_suspensao' => 'ignored',
        ]);

        self::assertSame(4, $immunity['issqnTipoImunidade']);
        self::assertSame('', $immunity['issqnNumeroProcessoSuspensao']);
    }

    public function testInvalidEnumsFallBackSafely(): void
    {
        $payload = (new IssqnPayloadResolver())->resolve([
            'tributacao_issqn' => 99,
            'tipo_retencao_iss' => 99,
            'issqn_tipo_suspensao' => '7',
        ]);

        self::assertSame(1, $payload['tributacaoIssqn']);
        self::assertSame(1, $payload['tipoRetencaoIss']);
        self::assertNull($payload['issqnTipoSuspensao']);
    }
}
