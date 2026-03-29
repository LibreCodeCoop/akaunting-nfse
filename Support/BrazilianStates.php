<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

final class BrazilianStates
{
    /**
     * @return array<int, array{uf: string, name: string}>
     */
    public function all(): array
    {
        return [
            ['uf' => 'AC', 'name' => 'Acre'],
            ['uf' => 'AL', 'name' => 'Alagoas'],
            ['uf' => 'AP', 'name' => 'Amapa'],
            ['uf' => 'AM', 'name' => 'Amazonas'],
            ['uf' => 'BA', 'name' => 'Bahia'],
            ['uf' => 'CE', 'name' => 'Ceara'],
            ['uf' => 'DF', 'name' => 'Distrito Federal'],
            ['uf' => 'ES', 'name' => 'Espirito Santo'],
            ['uf' => 'GO', 'name' => 'Goias'],
            ['uf' => 'MA', 'name' => 'Maranhao'],
            ['uf' => 'MT', 'name' => 'Mato Grosso'],
            ['uf' => 'MS', 'name' => 'Mato Grosso do Sul'],
            ['uf' => 'MG', 'name' => 'Minas Gerais'],
            ['uf' => 'PA', 'name' => 'Para'],
            ['uf' => 'PB', 'name' => 'Paraiba'],
            ['uf' => 'PR', 'name' => 'Parana'],
            ['uf' => 'PE', 'name' => 'Pernambuco'],
            ['uf' => 'PI', 'name' => 'Piaui'],
            ['uf' => 'RJ', 'name' => 'Rio de Janeiro'],
            ['uf' => 'RN', 'name' => 'Rio Grande do Norte'],
            ['uf' => 'RS', 'name' => 'Rio Grande do Sul'],
            ['uf' => 'RO', 'name' => 'Rondonia'],
            ['uf' => 'RR', 'name' => 'Roraima'],
            ['uf' => 'SC', 'name' => 'Santa Catarina'],
            ['uf' => 'SP', 'name' => 'Sao Paulo'],
            ['uf' => 'SE', 'name' => 'Sergipe'],
            ['uf' => 'TO', 'name' => 'Tocantins'],
        ];
    }
}
