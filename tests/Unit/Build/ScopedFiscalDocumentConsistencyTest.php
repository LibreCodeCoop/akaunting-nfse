<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Build;

use Modules\Nfse\Application\AuthorizedFiscalSnapshotExtractor;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Danfse\DanfseGenerator;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Danfse\DanfseTemplate;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Danfse\XmlToArray;
use PHPUnit\Framework\TestCase;

/**
 * Consumer-side contract: DANFSe reads authorized XML, never a commercial
 * invoice or an estimated municipal tax. Uses the exact pinned/scoped library.
 */
final class ScopedFiscalDocumentConsistencyTest extends TestCase
{
    public function testAuthorizedXmlSnapshotAndDanfseAgreeOnActualFiscalFields(): void
    {
        $xml = self::authorizedXml();
        $snapshot = (new AuthorizedFiscalSnapshotExtractor())->extract($xml);
        $data = (new DanfseTemplate())->buildData((new XmlToArray())->convert($xml));
        $html = (new DanfseGenerator())->generateHtml($xml);

        self::assertSame(hash('sha256', $xml), $snapshot['source_sha256']);
        self::assertSame('2026-01-15', $snapshot['competence_date']);
        self::assertSame('1500.00', $snapshot['gross_service_value']);
        self::assertSame('1292.75', $snapshot['liquid_value']);
        self::assertSame('9.75', $snapshot['federal']['pis']);
        self::assertSame('45.00', $snapshot['federal']['cofins']);

        self::assertSame('R$ 1.500,00', $data['totais']['valor_servico']);
        self::assertSame('R$ 1.292,75', $data['totais']['valor_liquido']);
        self::assertSame('R$ 15,00', $data['tributacao_federal']['contribuicoes_sociais']);
        self::assertSame('2,00%', $data['totais_tributos']['municipais']);
        self::assertStringContainsString('R$ 1.292,75', $html);
        self::assertStringContainsString('R$ 1.500,00', $html);

        // The authorized infNFSe has no final ISS result. The existence of
        // a DPS-level ISS amount, or a 2% estimated tax disclosure, cannot
        // silently synthesize an official calculated ISS for the renderer.
        self::assertSame('-', $data['tributacao_municipal']['issqn_apurado']);
    }

    public function testAlteredAuthorizedTotalsDoNotRecalculateTheOriginalOrOverwriteHistory(): void
    {
        $original = self::authorizedXml();
        $modified = str_replace(
            '<vLiq>1292.75</vLiq>',
            '<vLiq>1300.00</vLiq>',
            $original,
        );
        self::assertNotSame($original, $modified);

        $extractor = new AuthorizedFiscalSnapshotExtractor();
        $before = $extractor->extract($original);
        $after = $extractor->extract($modified);
        $template = new DanfseTemplate();
        $rendered = $template->buildData((new XmlToArray())->convert($modified));

        self::assertSame('1292.75', $before['liquid_value']);
        self::assertSame('1300.00', $after['liquid_value']);
        self::assertNotSame($before['source_sha256'], $after['source_sha256']);
        self::assertSame('R$ 1.300,00', $rendered['totais']['valor_liquido']);
        self::assertSame('R$ 1.500,00', $rendered['totais']['valor_servico']);
        self::assertSame('-', $rendered['tributacao_municipal']['issqn_apurado']);
    }

    private static function authorizedXml(): string
    {
        $xml = file_get_contents(dirname(__DIR__, 2) . '/fixtures/nfse_exemplo.xml');
        self::assertIsString($xml);

        return $xml;
    }
}
