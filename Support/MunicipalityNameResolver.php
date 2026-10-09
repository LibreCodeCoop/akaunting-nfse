<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Domain\OfficialDomainCatalog;
use ReflectionClass;

/**
 * Resolves an exact city and UF pair from the offline official NFS-e/IBGE table.
 * Refuses ambiguous matches; never infers a city solely from a postal code.
 */
final class MunicipalityNameResolver
{
    /** @var array<string,string> */
    private const STATE_NAMES = [
        'acre' => 'AC',
        'alagoas' => 'AL',
        'amapa' => 'AP',
        'amazonas' => 'AM',
        'bahia' => 'BA',
        'ceara' => 'CE',
        'distrito federal' => 'DF',
        'espirito santo' => 'ES',
        'goias' => 'GO',
        'maranhao' => 'MA',
        'mato grosso' => 'MT',
        'mato grosso do sul' => 'MS',
        'minas gerais' => 'MG',
        'para' => 'PA',
        'paraiba' => 'PB',
        'parana' => 'PR',
        'pernambuco' => 'PE',
        'piaui' => 'PI',
        'rio de janeiro' => 'RJ',
        'rio grande do norte' => 'RN',
        'rio grande do sul' => 'RS',
        'rondonia' => 'RO',
        'roraima' => 'RR',
        'santa catarina' => 'SC',
        'sao paulo' => 'SP',
        'sergipe' => 'SE',
        'tocantins' => 'TO',
    ];

    public function __construct(private readonly ?string $tablePath = null)
    {
    }

    public function resolve(string $city, string $state): string
    {
        $city = self::normalized($city);
        $state = strtoupper(trim($state));
        if (strlen($state) !== 2) {
            $state = self::STATE_NAMES[self::normalized($state)] ?? '';
        }

        if ($city === '' || !in_array($state, self::STATE_NAMES, true)) {
            return '';
        }

        $path = $this->tablePath ?? $this->officialTablePath();
        if (!is_readable($path)) {
            return '';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $matches = [];
        try {
            while (($line = fgets($handle)) !== false) {
                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                $parts = explode("\t", trim($line));
                if (count($parts) < 3 || trim($parts[1]) !== $state) {
                    continue;
                }

                if (self::normalized($parts[2]) === $city
                    && preg_match('/^\d{7}$/D', $parts[0]) === 1) {
                    $matches[$parts[0]] = true;
                    if (count($matches) > 1) {
                        return '';
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return (string) (array_key_first($matches) ?? '');
    }

    private function officialTablePath(): string
    {
        // The scoped SDK ships the same official, versioned offline table.
        // No remote municipality lookup takes place during issuance.
        $filename = (new ReflectionClass(OfficialDomainCatalog::class))->getFileName();
        return dirname((string) $filename, 3) . '/resources/domains/municipios-ibge-v1.00.tsv';
    }

    private static function normalized(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);

        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }
}
