<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\FinalDpsIbsCbsReadiness;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FinalDpsIbsCbsReadinessTest extends TestCase
{
    #[DataProvider('legalDates')]
    public function testExistingObligationPolicyIsAppliedToTheFinalDps(
        string $competence,
        string $service,
        int $simples,
        bool $ready,
    ): void {
        $result = (new FinalDpsIbsCbsReadiness())->evaluate(
            $this->dps([
                'dataCompetencia' => $competence,
                'itemListaServico' => $service,
                'opcaoSimplesNacional' => $simples,
            ]),
        );

        self::assertSame($ready, $result['isReady']);
        self::assertSame(!$ready, $result['required']);
        if (!$ready) {
            self::assertContains('ibs_cbs_enabled', $result['missing']);
        }
    }

    /** @return array<string,array{string,string,int,bool}> */
    public static function legalDates(): array
    {
        return [
            'before general effective date' => ['2026-09-30', '0107', 1, true],
            'ordinary service after date' => ['2026-10-01', '0107', 1, false],
            'deferred service before date' => ['2026-11-30', '0103', 1, true],
            'deferred service after date' => ['2026-12-01', '0103', 1, false],
            'Simples in 2026' => ['2026-12-31', '0107', 3, true],
            'Simples from 2027' => ['2027-01-01', '0107', 3, false],
        ];
    }

    public function testUnverifiableCompetenceDoesNotManufactureCompliance(): void
    {
        $result = (new FinalDpsIbsCbsReadiness())->evaluate(
            $this->dps(['dataCompetencia' => null]),
        );

        self::assertFalse($result['isReady']);
        self::assertSame('unverifiable', $result['reason']);
        self::assertContains('ibs_cbs_obligation_context', $result['missing']);
    }

    public function testCompleteIbsCbsDataOnDpsCanPassThePolicy(): void
    {
        $result = (new FinalDpsIbsCbsReadiness())->evaluate(
            $this->dps($this->completeIbsCbs()),
        );

        self::assertTrue($result['isReady']);
        self::assertTrue($result['required']);
        self::assertSame([], $result['missing']);
    }

    #[DataProvider('invalidIbsCbs')]
    public function testPayloadCannotBypassRequiredOrOptionalIbsCbsFieldChecks(
        array $changes,
        string $reason,
        string $field,
    ): void {
        $payload = array_replace($this->completeIbsCbs(), $changes);
        $result = (new FinalDpsIbsCbsReadiness())->evaluate($this->dps($payload));

        self::assertFalse($result['isReady']);
        self::assertSame($reason, $result['reason']);
        self::assertContains($field, $result['missing']);
    }

    /** @return array<string,array{array<string,mixed>,string,string}> */
    public static function invalidIbsCbs(): array
    {
        return [
            'partial with null finalidade' => [
                ['ibsCbsFinalidade' => null],
                'partial_ibs_cbs_group', 'ibs_cbs_finalidade',
            ],
            'missing ind final' => [
                ['ibsCbsIndFinal' => null],
                'invalid_ibs_cbs_ind_final', 'ibs_cbs_ind_final',
            ],
            'unsupported credit purpose' => [
                ['ibsCbsFinalidade' => 1],
                'unsupported_ibs_cbs_finalidade', 'ibs_cbs_finalidade',
            ],
            'unsupported debit purpose' => [
                ['ibsCbsFinalidade' => 2],
                'unsupported_ibs_cbs_finalidade', 'ibs_cbs_finalidade',
            ],
            'invalid finalidade' => [
                ['ibsCbsFinalidade' => 8],
                'unsupported_ibs_cbs_finalidade', 'ibs_cbs_finalidade',
            ],
            'invalid code' => [
                ['ibsCbsCodigoIndicadorOperacao' => 'X'],
                'general_lc116_service', 'ibs_cbs_c_ind_op',
            ],
            'missing NBS' => [
                ['codigoNbs' => ''],
                'invalid_ibs_cbs_nbs', 'codigo_nbs',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function completeIbsCbs(): array
    {
        return [
            'dataCompetencia' => '2026-11-01',
            'codigoNbs' => '115011000',
            'ibsCbsFinalidade' => 0,
            'ibsCbsIndFinal' => 1,
            'ibsCbsCodigoIndicadorOperacao' => '100001',
            'ibsCbsIndDest' => 0,
            'ibsCbsCst' => '000',
            'ibsCbsClassificacaoTributaria' => '000001',
        ];
    }

    /** @param array<string,mixed> $changes */
    private function dps(array $changes = []): DpsData
    {
        return new DpsData(...array_replace([
            'cnpjPrestador' => '11222333000181',
            'municipioIbge' => '3303302',
            'itemListaServico' => '0107',
            'valorServico' => '100.00',
            'aliquota' => '2.00',
            'discriminacao' => 'Synthetic consulting',
            'dataCompetencia' => '2026-09-01',
        ], $changes));
    }
}
