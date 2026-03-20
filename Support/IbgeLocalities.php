<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

final class IbgeLocalities
{
    /**
     * @param array<int, array{id?: int|string, sigla?: string, nome?: string}> $rows
     * @return array<int, array{uf: string, name: string}>
     */
    public function mapUfs(array $rows): array
    {
        $mapped = [];

        foreach ($rows as $row) {
            $uf = strtoupper(trim((string) ($row['sigla'] ?? '')));
            $name = trim((string) ($row['nome'] ?? ''));

            if (strlen($uf) !== 2 || $name === '') {
                continue;
            }

            $mapped[] = [
                'uf' => $uf,
                'name' => $name,
            ];
        }

        usort($mapped, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $mapped;
    }

    /**
     * @param array<int, array{id?: int|string, nome?: string}> $rows
     * @return array<int, array{ibge_code: string, name: string}>
     */
    public function mapMunicipalities(array $rows): array
    {
        $mapped = [];

        foreach ($rows as $row) {
            $ibgeCode = preg_replace('/\D/', '', (string) ($row['id'] ?? ''));
            $name = trim((string) ($row['nome'] ?? ''));

            if ($ibgeCode === '' || $name === '') {
                continue;
            }

            $mapped[] = [
                'ibge_code' => $ibgeCode,
                'name' => $name,
            ];
        }

        usort($mapped, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $mapped;
    }
}
