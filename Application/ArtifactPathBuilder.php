<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;

final class ArtifactPathBuilder
{
    public function __construct(
        private readonly ReceiptNumberResolver $numberResolver = new ReceiptNumberResolver(),
    ) {
    }

    public function basePath(
        string $template,
        string $cnpj,
        string $customerName,
        ReceiptData $receipt,
    ): string {
        $template = trim($template);

        if ($template === '') {
            $template = 'nfse/{cnpj}/{year}/{month}/{day}';
        }

        return trim(strtr(
            $template,
            $this->replacements($cnpj, $customerName, $receipt),
        ), '/');
    }

    public function filePath(
        string $basePath,
        string $template,
        string $cnpj,
        string $customerName,
        ReceiptData $receipt,
        string $extension,
    ): string {
        $template = trim($template);

        if ($template === '') {
            $template = '{chave_acesso}';
        }

        $fileName = trim(strtr(
            $template,
            $this->replacements($cnpj, $customerName, $receipt),
        ), '/');
        $fileName = trim($fileName, '.');

        if ($fileName === '') {
            $fileName = 'nao-informado';
        }

        $extension = ltrim(trim($extension), '.');

        if ($extension !== '' && !str_ends_with(strtolower($fileName), '.' . strtolower($extension))) {
            $fileName .= '.' . $extension;
        }

        return rtrim($basePath, '/') . '/' . $fileName;
    }

    /**
     * @return array<string,string>
     */
    public function replacements(string $cnpj, string $customerName, ReceiptData $receipt): array
    {
        $resolvedNumber = $this->numberResolver->resolve($receipt);

        try {
            $date = new \DateTimeImmutable($receipt->dataEmissao);
        } catch (\Exception) {
            $date = new \DateTimeImmutable('now');
        }

        return [
            '{cnpj}' => $cnpj,
            '{year}' => $date->format('Y'),
            '{month}' => $date->format('m'),
            '{day}' => $date->format('d'),
            '{month_name}' => $this->monthName((int) $date->format('n')),
            '{nfse_number}' => $this->sanitize($resolvedNumber !== '' ? $resolvedNumber : 'sem-numero'),
            '{chave_acesso}' => $this->sanitize($receipt->chaveAcesso !== '' ? $receipt->chaveAcesso : 'sem-chave-acesso'),
            '{customer_name}' => $this->sanitize($customerName !== '' ? $customerName : 'sem-cliente'),
        ];
    }

    private function monthName(int $month): string
    {
        return match ($month) {
            1 => 'janeiro',
            2 => 'fevereiro',
            3 => 'marco',
            4 => 'abril',
            5 => 'maio',
            6 => 'junho',
            7 => 'julho',
            8 => 'agosto',
            9 => 'setembro',
            10 => 'outubro',
            11 => 'novembro',
            12 => 'dezembro',
            default => 'mes-invalido',
        };
    }

    private function sanitize(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/[^\pL\pN]+/u', '-', $normalized);
        $normalized = is_string($normalized) ? trim($normalized, '-') : '';

        return $normalized !== '' ? $normalized : 'nao-informado';
    }
}
