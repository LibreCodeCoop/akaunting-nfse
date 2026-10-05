<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\AdnImportCandidateClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdnImportCandidateClassifierTest extends TestCase
{
    #[DataProvider('roleProvider')]
    public function testClassifiesCompanyRoleFromAuthorizedNfse(
        string $company,
        string $provider,
        string $taker,
        string $intermediary,
        string $expected,
    ): void {
        $xml = $this->xml($provider, $taker, $intermediary);

        self::assertSame(
            $expected,
            (new AdnImportCandidateClassifier())->classify(
                $xml,
                $company,
                'NFSE',
            ),
        );
    }

    public static function roleProvider(): array
    {
        return [
            'emitted' => ['11222333000181', '11222333000181', '99887766000155', '', 'emitted'],
            'received' => ['11222333000181', '99887766000155', '11222333000181', '', 'received'],
            'intermediated' => ['11222333000181', '99887766000155', '88776655000144', '11222333000181', 'intermediated'],
            'unrelated' => ['11222333000181', '99887766000155', '88776655000144', '', 'unknown'],
        ];
    }

    public function testEventsAreNeverImportCandidates(): void
    {
        self::assertSame(
            'event',
            (new AdnImportCandidateClassifier())->classify(
                $this->xml('99887766000155', '11222333000181', ''),
                '11222333000181',
                'EVENTO',
                '101101',
            ),
        );
    }

    public function testMalformedXmlIsUnverifiableInsteadOfGuessed(): void
    {
        self::assertSame(
            'unknown',
            (new AdnImportCandidateClassifier())->classify(
                '<NFSe>',
                '11222333000181',
                'NFSE',
            ),
        );
    }

    private function xml(string $provider, string $taker, string $intermediary): string
    {
        $intermediaryXml = $intermediary !== ''
            ? '<interm><CNPJ>' . $intermediary . '</CNPJ></interm>'
            : '';

        return '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe><DPS><infDPS>'
            . '<prest><CNPJ>' . $provider . '</CNPJ></prest>'
            . '<toma><CNPJ>' . $taker . '</CNPJ></toma>'
            . $intermediaryXml
            . '</infDPS></DPS></infNFSe></NFSe>';
    }
}
