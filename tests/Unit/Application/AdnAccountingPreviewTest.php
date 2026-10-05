<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\AdnAccountingPreview;
use PHPUnit\Framework\TestCase;

final class AdnAccountingPreviewTest extends TestCase
{
    public function testExtractsAccountingPreviewFromAuthorizedNfse(): void
    {
        $xml = <<<'XML'
<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse">
  <infNFSe>
    <prest><CNPJ>11222333000181</CNPJ><xNome>Fornecedor de Teste Ltda</xNome></prest>
    <DPS><infDPS>
      <dCompet>2026-10-01</dCompet>
      <serv><cServ><xDescServ>Consultoria de tecnologia</xDescServ></cServ></serv>
      <valores>
        <vServPrest><vServ>150.50</vServ></vServPrest>
        <trib><tribMun><vISSQN>3.01</vISSQN></tribMun><tribFed><vPis>0.98</vPis><vCofins>4.51</vCofins><vIRRF>2.25</vIRRF><vCSLL>1.50</vCSLL></tribFed></trib>
      </valores>
    </infDPS></DPS>
    <valores><vLiq>137.25</vLiq></valores>
  </infNFSe>
</NFSe>
XML;

        $preview = (new AdnAccountingPreview())->fromAuthorizedXml($xml);

        self::assertSame('Fornecedor de Teste Ltda', $preview['supplier_name']);
        self::assertSame('11222333000181', $preview['supplier_tax_number']);
        self::assertSame('2026-10-01', $preview['competence']);
        self::assertSame('Consultoria de tecnologia', $preview['service_description']);
        self::assertSame('150.50', $preview['gross_value']);
        self::assertSame('137.25', $preview['liquid_value']);
        self::assertSame('3.01', $preview['issqn_value']);
        self::assertSame('0.98', $preview['pis_value']);
        self::assertSame('4.51', $preview['cofins_value']);
    }

    public function testMissingOptionalTaxValuesRemainEmptyInsteadOfBeingGuessed(): void
    {
        $xml = '<NFSe><infNFSe><prest><xNome>Fornecedor</xNome></prest><DPS><infDPS><dCompet>2026-10-01</dCompet><valores><vServPrest><vServ>100.00</vServ></vServPrest></valores></infDPS></DPS></infNFSe></NFSe>';

        $preview = (new AdnAccountingPreview())->fromAuthorizedXml($xml);

        self::assertSame('', $preview['issqn_value']);
        self::assertSame('', $preview['pis_value']);
        self::assertSame('', $preview['cofins_value']);
        self::assertSame('', $preview['irrf_value']);
        self::assertSame('', $preview['csll_value']);
    }

    public function testRejectsMalformedXmlInsteadOfGuessingPreview(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AdnAccountingPreview())->fromAuthorizedXml('<NFSe>');
    }
}
