<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Fiscal issuer identity, never an Akaunting tenant identifier.
 *
 * The official DPS identity pads CPF to 14 positions and preserves CNPJ
 * letters. It is not a proof that an environment supports alphanumeric CNPJ.
 */
final readonly class DpsFiscalIssuer
{
    public string $registration;

    public function __construct(
        public string $municipality,
        public int $registrationType,
        string $registration,
    ) {
        if (preg_match('/^[0-9]{7}$/D', $municipality) !== 1) {
            throw new \InvalidArgumentException('Fiscal issuer municipality must have seven digits.');
        }

        $registration = strtoupper(trim($registration));

        if ($registrationType === 1) {
            if (preg_match('/^[0-9]{11}$/D', $registration) !== 1) {
                throw new \InvalidArgumentException('Fiscal issuer CPF must have eleven digits.');
            }

            $this->registration = str_pad($registration, 14, '0', STR_PAD_LEFT);

            return;
        }

        if ($registrationType !== 2 || preg_match('/^[0-9A-Z]{14}$/D', $registration) !== 1) {
            throw new \InvalidArgumentException('Fiscal issuer CNPJ must have fourteen alphanumeric characters.');
        }

        $this->registration = $registration;
    }
}
