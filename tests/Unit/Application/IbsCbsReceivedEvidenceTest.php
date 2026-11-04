<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\IbsCbsReceivedEvidence;
use PHPUnit\Framework\TestCase;

final class IbsCbsReceivedEvidenceTest extends TestCase
{
    public function testReceivedValuesAreObservedNotRecognizedCredits(): void
    {
        $xml = self::xml('2.34', '0.00');
        $result = (new IbsCbsReceivedEvidence())->fromAuthorizedXml($xml);

        self::assertSame(hash('sha256', $xml), $result['source_sha256']);
        self::assertSame('2026-10-10', $result['competence']);
        self::assertSame('unverifiable', $result['credit_eligibility']);
        self::assertSame(['amount' => '2.34', 'status' => 'reported_amount'], $result['ibs']);
        self::assertSame(['amount' => '0.00', 'status' => 'reported_zero'], $result['cbs']);
    }

    public function testMissingGroupsNeverBecomeZeroOrTaxCredits(): void
    {
        $result = (new IbsCbsReceivedEvidence())->fromAuthorizedXml(
            '<NFSe><infNFSe><DPS><infDPS><dCompet>2026-10-10</dCompet></infDPS></DPS></infNFSe></NFSe>',
        );

        self::assertSame(['amount' => null, 'status' => 'not_reported'], $result['ibs']);
        self::assertSame(['amount' => null, 'status' => 'not_reported'], $result['cbs']);
        self::assertSame('unverifiable', $result['credit_eligibility']);
    }

    public function testSubCentAndNegativeValuesAreNotRoundedOrDeducted(): void
    {
        $result = (new IbsCbsReceivedEvidence())->fromAuthorizedXml(self::xml('1.005', '-2.00'));

        self::assertSame(['amount' => null, 'status' => 'invalid_amount'], $result['ibs']);
        self::assertSame(['amount' => null, 'status' => 'invalid_amount'], $result['cbs']);
    }

    public function testValueOutsideAuthorizedIbsCbsGroupIsIgnored(): void
    {
        $xml = '<NFSe><infNFSe><IBSCBS><valores><vBC>100.00</vBC></valores></IBSCBS><vIBSTot>99.00</vIBSTot></infNFSe></NFSe>';
        $result = (new IbsCbsReceivedEvidence())->fromAuthorizedXml($xml);

        self::assertSame('not_reported', $result['ibs']['status']);
    }

    public function testDtdAndMalformedXmlAreRejectedWithoutNetworkAccess(): void
    {
        $reader = new IbsCbsReceivedEvidence();
        foreach ([
            '<!DOCTYPE NFSe [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><NFSe/>',
            '<NFSe>',
        ] as $xml) {
            try {
                $reader->fromAuthorizedXml($xml);
                self::fail('Expected invalid XML to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    private static function xml(string $ibs, string $cbs): string
    {
        return '<NFSe><infNFSe><DPS><infDPS><dCompet>2026-10-10</dCompet></infDPS></DPS>'
            . '<IBSCBS><valores><vBC>100.00</vBC><gIBS><vIBSTot>' . $ibs
            . '</vIBSTot></gIBS><gCBS><vCBS>' . $cbs
            . '</vCBS></gCBS></valores></IBSCBS></infNFSe></NFSe>';
    }
}
