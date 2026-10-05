<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\AuthorizedFiscalSnapshotExtractor;
use PHPUnit\Framework\TestCase;

final class AuthorizedFiscalSnapshotExtractorTest extends TestCase
{
    public function testExtractsAuthorizedFiscalValuesWithProvenance(): void
    {
        $xml = $this->authorizedXml();
        $snapshot = (new AuthorizedFiscalSnapshotExtractor())->extract($xml);

        self::assertSame(1, $snapshot['schema_version']);
        self::assertSame('authorized_xml', $snapshot['source']);
        self::assertSame(hash('sha256', $xml), $snapshot['source_sha256']);
        self::assertSame('2026-10-05', $snapshot['competence_date']);
        self::assertSame('1500.00', $snapshot['gross_service_value']);
        self::assertSame('1292.75', $snapshot['liquid_value']);
        self::assertSame([
            'taxation' => '1',
            'retention_type' => '2',
            'rate' => '2.00',
            'calculation_base' => '1350.00',
            'value' => '27.00',
        ], $snapshot['issqn']);
        self::assertSame('9.75', $snapshot['federal']['pis']);
        self::assertSame('45.00', $snapshot['federal']['cofins']);
        self::assertSame('22.50', $snapshot['federal']['irrf']);
        self::assertSame('15.00', $snapshot['federal']['social_security']);
        self::assertSame('15.00', $snapshot['federal']['csll']);
        self::assertSame('1000.00', $snapshot['ibs_cbs']['calculation_base']);
        self::assertSame('12.00', $snapshot['ibs_cbs']['ibs_total']);
        self::assertSame('8.00', $snapshot['ibs_cbs']['cbs_total']);
        self::assertSame('1480.00', $snapshot['ibs_cbs']['nfse_total']);
    }

    public function testMissingOptionalTaxGroupsRemainNullInsteadOfBeingGuessed(): void
    {
        $snapshot = (new AuthorizedFiscalSnapshotExtractor())->extract(
            '<NFSe><infNFSe><valores><vLiq>100.00</vLiq></valores><DPS><infDPS><dCompet>2026-10-05</dCompet><valores><vServPrest><vServ>100.00</vServ></vServPrest></valores></infDPS></DPS></infNFSe></NFSe>',
        );

        self::assertNull($snapshot['issqn']['value']);
        self::assertNull($snapshot['federal']['pis']);
        self::assertNull($snapshot['ibs_cbs']['ibs_total']);
    }

    public function testRejectsDoctypeBeforeXmlParsing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DOCTYPE is not allowed');

        (new AuthorizedFiscalSnapshotExtractor())->extract(
            '<!DOCTYPE NFSe [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><NFSe>&xxe;</NFSe>',
        );
    }

    private function authorizedXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse">
  <infNFSe>
    <valores><vLiq>1292.75</vLiq></valores>
    <IBSCBS>
      <valores><vBC>1000.00</vBC></valores>
      <totais>
        <gIBS><vIBSTot>12.00</vIBSTot></gIBS>
        <gCBS><vCBS>8.00</vCBS></gCBS>
        <vTotNF>1480.00</vTotNF>
      </totais>
    </IBSCBS>
    <DPS>
      <infDPS>
        <dCompet>2026-10-05</dCompet>
        <valores>
          <vServPrest><vServ>1500.00</vServ></vServPrest>
          <trib>
            <tribMun>
              <tribISSQN>1</tribISSQN>
              <tpRetISSQN>2</tpRetISSQN>
              <pAliq>2.00</pAliq>
              <vBC>1350.00</vBC>
              <vISSQN>27.00</vISSQN>
            </tribMun>
            <tribFed>
              <piscofins><vPis>9.75</vPis><vCofins>45.00</vCofins></piscofins>
              <vRetIRRF>22.50</vRetIRRF>
              <vRetCP>15.00</vRetCP>
              <vRetCSLL>15.00</vRetCSLL>
            </tribFed>
          </trib>
        </valores>
      </infDPS>
    </DPS>
  </infNFSe>
</NFSe>
XML;
    }
}
