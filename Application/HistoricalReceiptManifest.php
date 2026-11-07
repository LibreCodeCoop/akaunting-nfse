<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use DateTimeImmutable;
use DomainException;

/**
 * A historical NFS-e is not a new accounting invoice or fiscal issuance.
 * Normalization deliberately never invents commercial due dates or payments.
 */
final class HistoricalReceiptManifest
{
    /** @param array<string, mixed> $raw
     *  @return array<string, mixed>
     */
    public static function normalize(array $raw, int $companyId, string $issuerCnpj): array
    {
        if ($companyId <= 0) {
            throw new DomainException('Historical receipt requires a tenant.');
        }

        if (isset($raw['invoice_id']) && $raw['invoice_id'] !== '' && $raw['invoice_id'] !== null) {
            throw new DomainException('Historical archive cannot invent an invoice link.');
        }

        $issuer = self::digits($raw['issuer_tax'] ?? '');
        if ($issuer === '' || $issuer !== self::digits($issuerCnpj) || !self::validTaxId($issuer)) {
            throw new DomainException('The historical issuer differs from the company fiscal identity.');
        }

        $recipient = self::digits($raw['recipient_tax'] ?? '');
        if (!self::validTaxId($recipient)) {
            throw new DomainException('Historical recipient tax ID failed validation.');
        }

        $number = trim((string) ($raw['nfse_number'] ?? ''));
        if (!preg_match('/^[0-9]{1,15}$/D', $number)) {
            throw new DomainException('Historical NFS-e number must be numeric.');
        }

        $date = trim((string) ($raw['data_emissao'] ?? ''));
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date);
        if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $date) {
            throw new DomainException('Historical issue date must be exact, including time.');
        }

        $status = (string) ($raw['status'] ?? '');
        if (!in_array($status, ['emitted', 'cancelled'], true)) {
            throw new DomainException('Historical receipt must be emitted or cancelled.');
        }

        $path = ltrim(trim((string) ($raw['danfse_webdav_path'] ?? '')), '/');
        if (strlen($path) > 255 || !str_starts_with($path, 'LibreCode/')
            || !str_ends_with(strtolower($path), '.pdf')
            || preg_match('~(?:^|/)\.\.(?:/|$)|[?#\\\\\x00-\x1F]~', $path)) {
            throw new DomainException('Historical WebDAV path is not an approved archive PDF.');
        }

        $sha = strtolower(trim((string) ($raw['archive_pdf_sha256'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/D', $sha)) {
            throw new DomainException('Historical archive SHA-256 is missing or invalid.');
        }

        $page = $raw['archive_page_number'] ?? 0;
        if (!is_numeric($page) || (int) $page < 1 || (int) $page > 500
            || (string) (int) $page !== (string) $page) {
            throw new DomainException('Historical PDF page must be a positive integer.');
        }

        $evidence = $raw['archive_evidence'] ?? [];
        if (!is_array($evidence) || array_is_list($evidence)) {
            throw new DomainException('Historical documentary evidence must be an object.');
        }

        $gross = $evidence['gross_cents'] ?? null;
        if ($status === 'emitted' && (!is_int($gross) || $gross <= 0)) {
            throw new DomainException('An emitted historical NFS-e requires a documented gross amount.');
        }

        if ($gross !== null && (!is_int($gross) || $gross < 0)) {
            throw new DomainException('Invalid fiscal gross amount.');
        }

        $evidence['source_kind'] = 'archival_nfse_pdf';
        $evidence['issuer_tax'] = $issuer;
        $evidence['recipient_tax'] = $recipient;
        $evidence['recipient_name'] = trim((string) ($raw['recipient_name_ocr'] ?? $evidence['recipient_name'] ?? ''));
        $evidence['recipient_name_source'] = trim((string) ($raw['recipient_name_ocr'] ?? '')) !== ''
            ? 'ocr_unverified'
            : ($evidence['recipient_name_source'] ?? 'unknown');
        $evidence['replaces_fiscal_number'] = trim((string) ($raw['replaces_fiscal_number'] ?? ''));
        $evidence['replaced_by_fiscal_number'] = trim((string) ($raw['replaced_by_fiscal_number'] ?? ''));
        $evidence['review_issues'] = $raw['review_issues'] ?? [];
        $evidence['original_document_page'] = (int) $page;
        $evidence['original_document_sha256'] = $sha;

        $year = $parsed->format('Y');
        $identity = hash('sha256', implode("\0", [
            'historical-nfse-v1', (string) $companyId, $issuer, $year, ltrim($number, '0') ?: '0',
        ]));

        return [
            'invoice_id' => null,
            'company_id' => $companyId,
            'archive_identity' => $identity,
            'nfse_number' => $number,
            'data_emissao' => $date,
            'competence_date' => $raw['competence_date'] ?? null,
            'status' => $status,
            'codigo_verificacao' => trim((string) ($raw['codigo_verificacao'] ?? '')) ?: null,
            'danfse_webdav_path' => $path,
            'archive_pdf_sha256' => $sha,
            'archive_page_number' => (int) $page,
            'archive_evidence' => $evidence,
            'recipient_contact_id' => null,
        ];
    }

    public static function digits(mixed $value): string
    {
        return preg_replace('/[^0-9]/', '', (string) $value) ?? '';
    }

    public static function validTaxId(string $taxId): bool
    {
        if ($taxId === '' || count(array_unique(str_split($taxId))) === 1) {
            return false;
        }

        if (strlen($taxId) === 11) {
            $base = array_map('intval', str_split($taxId));
            for ($n = 9; $n <= 10; ++$n) {
                $sum = 0;
                for ($i = 0; $i < $n; ++$i) {
                    $sum += $base[$i] * ($n + 1 - $i);
                }
                $digit = ($sum * 10) % 11;
                if ($base[$n] !== ($digit === 10 ? 0 : $digit)) {
                    return false;
                }
            }

            return true;
        }

        if (strlen($taxId) === 14) {
            $base = array_map('intval', str_split($taxId));
            foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
                $sum = 0;
                foreach ($weights as $i => $weight) {
                    $sum += $base[$i] * $weight;
                }
                $rest = $sum % 11;
                $digit = $rest < 2 ? 0 : 11 - $rest;
                if ($digit !== $base[count($weights)]) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
