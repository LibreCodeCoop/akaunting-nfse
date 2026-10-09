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
            'logradouro' => 'Avenida Das Nacoes Unidas',
            'numero' => '11541',
            'complemento' => 'Conj 61/62 e 71/72 Andar 6 e 7',
            'bairro' => 'Brooklin Novo',
        ], (new NationalTakerAddressParser())->parse(
            'Avenida Das Nacoes Unidas, 11541, Conj 61/62 e 71/72 Andar 6 e 7, Brooklin Novo',
        ));
    }

    public function testRefusesAmbiguousFreeFormAddresses(): void
    {
        $parser = new NationalTakerAddressParser();
        self::assertNull($parser->parse('Avenida Nacoes Unidas 11541 Brooklin Novo'));
        self::assertNull($parser->parse('Rua Teste, 3, Sala 2'));
    }
}
