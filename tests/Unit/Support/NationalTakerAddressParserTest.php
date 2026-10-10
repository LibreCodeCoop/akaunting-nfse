<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\NationalTakerAddressParser;
use PHPUnit\Framework\TestCase;

final class NationalTakerAddressParserTest extends TestCase
{
    public function testParsesOnlyClearlySeparatedStreetNumberComplementAndDistrict(): void
    {
        self::assertSame([
            'logradouro' => 'Rua Ficticia',
            'numero' => '123',
            'complemento' => 'Sala 45',
            'bairro' => 'Bairro Exemplo',
        ], (new NationalTakerAddressParser())->parse(
            'Rua Ficticia, 123, Sala 45, Bairro Exemplo',
        ));
    }

    public function testRefusesAmbiguousFreeFormAddresses(): void
    {
        $parser = new NationalTakerAddressParser();
        self::assertNull($parser->parse('Rua Ficticia 123 Bairro Exemplo'));
        self::assertNull($parser->parse('Rua Teste, 3, Sala 2'));
    }
}
