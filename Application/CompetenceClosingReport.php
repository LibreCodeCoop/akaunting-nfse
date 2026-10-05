<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Builds a competence closing exclusively from persisted authorized fiscal snapshots.
 *
 * Cancelled and substituted source documents remain visible for audit but never
 * contribute to fiscal totals. Replacements and grouped emissions are reconciled
 * by summing the active authorized documents linked to the same Akaunting invoice.
 */
final class CompetenceClosingReport
{
    /**
     * @param iterable<object> $receipts
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   summary:array<string,mixed>,
     *   reconciliation:list<array<string,mixed>>
     * }
     */
    public function build(iterable $receipts): array
    {
        $rows = [];
        $invoiceState = [];
        $summary = [
            'documents' => ['emitted' => 0, 'substituted' => 0, 'cancelled' => 0, 'other' => 0],
            'gross_service_value' => 0,
            'liquid_value' => 0,
            'taxation' => ['taxable' => 0, 'immunity' => 0, 'export' => 0, 'non_incidence' => 0, 'unknown' => 0],
            'iss' => ['retained' => 0, 'not_retained' => 0, 'unknown' => 0, 'value' => 0],
            'federal' => ['pis' => 0, 'cofins' => 0, 'irrf' => 0, 'social_security' => 0, 'csll' => 0],
            'ibs_cbs' => ['ibs_total' => 0, 'cbs_total' => 0, 'nfse_total' => 0],
            'unresolved_documents' => 0,
            'reconciliation_mismatches' => 0,
        ];

        foreach ($receipts as $receipt) {
            $invoice = $receipt->invoice ?? null;
            $snapshot = is_array($receipt->authorized_fiscal_snapshot ?? null)
                ? $receipt->authorized_fiscal_snapshot
                : null;
            $status = trim((string) ($receipt->status ?? ''));
            $statusBucket = array_key_exists($status, $summary['documents']) ? $status : 'other';
            $summary['documents'][$statusBucket]++;

            $invoiceId = (int) ($receipt->invoice_id ?? ($invoice->id ?? 0));
            $gross = $this->snapshotMinor($snapshot, ['gross_service_value']);
            $liquid = $this->snapshotMinor($snapshot, ['liquid_value']);
            $taxation = $this->taxationGroup($this->snapshotString($snapshot, ['issqn', 'taxation']));
            $retention = $this->retentionGroup($this->snapshotString($snapshot, ['issqn', 'retention_type']));
            $active = $status === 'emitted';
            $hasSnapshot = $snapshot !== null
                && (($snapshot['source'] ?? null) === 'authorized_xml')
                && is_string($snapshot['source_sha256'] ?? null)
                && ($snapshot['source_sha256'] ?? '') !== '';

            $row = [
                'receipt_id' => (int) ($receipt->id ?? 0),
                'invoice_id' => $invoiceId,
                'invoice_number' => (string) ($invoice->document_number ?? ''),
                'customer' => (string) ($invoice->contact_name ?? ''),
                'competence' => $this->dateString($receipt->competence_date ?? null),
                'status' => $status,
                'nfse_number' => (string) ($receipt->nfse_number ?? ''),
                'access_key' => (string) ($receipt->chave_acesso ?? ''),
                'gross_service_value' => $this->formatMinor($gross),
                'liquid_value' => $this->formatMinor($liquid),
                'taxation_group' => $taxation,
                'iss_retention' => $retention,
                'issqn_value' => $this->formatMinor($this->snapshotMinor($snapshot, ['issqn', 'value'])),
                'pis' => $this->formatMinor($this->snapshotMinor($snapshot, ['federal', 'pis'])),
                'cofins' => $this->formatMinor($this->snapshotMinor($snapshot, ['federal', 'cofins'])),
                'irrf' => $this->formatMinor($this->snapshotMinor($snapshot, ['federal', 'irrf'])),
                'social_security' => $this->formatMinor($this->snapshotMinor($snapshot, ['federal', 'social_security'])),
                'csll' => $this->formatMinor($this->snapshotMinor($snapshot, ['federal', 'csll'])),
                'ibs_total' => $this->formatMinor($this->snapshotMinor($snapshot, ['ibs_cbs', 'ibs_total'])),
                'cbs_total' => $this->formatMinor($this->snapshotMinor($snapshot, ['ibs_cbs', 'cbs_total'])),
                'nfse_total' => $this->formatMinor($this->snapshotMinor($snapshot, ['ibs_cbs', 'nfse_total'])),
                'source_sha256' => (string) ($snapshot['source_sha256'] ?? ''),
                'has_authorized_snapshot' => $hasSnapshot,
                'reconciliation_status' => 'pending',
            ];
            $rows[] = $row;
            $rowIndex = array_key_last($rows);

            if (!isset($invoiceState[$invoiceId])) {
                $invoiceState[$invoiceId] = [
                    'invoice_id' => $invoiceId,
                    'invoice_number' => (string) ($invoice->document_number ?? ''),
                    'customer' => (string) ($invoice->contact_name ?? ''),
                    'invoice_amount' => $this->decimalToMinor((string) ($invoice->amount ?? '0')),
                    'active_gross' => 0,
                    'active_rows' => [],
                    'missing_snapshot' => false,
                ];
            }

            if ($active) {
                $invoiceState[$invoiceId]['active_rows'][] = $rowIndex;

                if (!$hasSnapshot || $gross === null) {
                    $invoiceState[$invoiceId]['missing_snapshot'] = true;
                    $summary['unresolved_documents']++;
                    continue;
                }

                $invoiceState[$invoiceId]['active_gross'] += $gross;
                $summary['gross_service_value'] += $gross;
                $summary['liquid_value'] += $liquid ?? 0;
                $summary['taxation'][$taxation] += $gross;

                if ($retention === 'retained') {
                    $summary['iss']['retained'] += $gross;
                } elseif ($retention === 'not_retained') {
                    $summary['iss']['not_retained'] += $gross;
                } else {
                    $summary['iss']['unknown'] += $gross;
                }

                $summary['iss']['value'] += $this->snapshotMinor($snapshot, ['issqn', 'value']) ?? 0;

                foreach (array_keys($summary['federal']) as $key) {
                    $summary['federal'][$key] += $this->snapshotMinor($snapshot, ['federal', $key]) ?? 0;
                }

                foreach (array_keys($summary['ibs_cbs']) as $key) {
                    $summary['ibs_cbs'][$key] += $this->snapshotMinor($snapshot, ['ibs_cbs', $key]) ?? 0;
                }
            }
        }

        $reconciliation = [];

        foreach ($invoiceState as $state) {
            if ($state['active_rows'] === []) {
                continue;
            }

            $matches = !$state['missing_snapshot']
                && $state['invoice_amount'] === $state['active_gross'];

            if (!$matches) {
                $summary['reconciliation_mismatches']++;
                $reconciliation[] = [
                    'invoice_id' => $state['invoice_id'],
                    'invoice_number' => $state['invoice_number'],
                    'customer' => $state['customer'],
                    'invoice_amount' => $this->formatMinor($state['invoice_amount']),
                    'authorized_active_gross' => $this->formatMinor($state['active_gross']),
                    'reason' => $state['missing_snapshot'] ? 'missing_authorized_snapshot' : 'amount_mismatch',
                ];
            }

            foreach ($state['active_rows'] as $rowIndex) {
                $rows[$rowIndex]['reconciliation_status'] = $matches ? 'matched' : 'mismatch';
            }
        }

        $summary['gross_service_value'] = $this->formatMinor($summary['gross_service_value']);
        $summary['liquid_value'] = $this->formatMinor($summary['liquid_value']);

        foreach ($summary['taxation'] as $key => $value) {
            $summary['taxation'][$key] = $this->formatMinor($value);
        }
        foreach ($summary['iss'] as $key => $value) {
            if ($key !== 'unknown' || is_int($value)) {
                $summary['iss'][$key] = $this->formatMinor($value);
            }
        }
        foreach ($summary['federal'] as $key => $value) {
            $summary['federal'][$key] = $this->formatMinor($value);
        }
        foreach ($summary['ibs_cbs'] as $key => $value) {
            $summary['ibs_cbs'][$key] = $this->formatMinor($value);
        }

        return [
            'rows' => $rows,
            'summary' => $summary,
            'reconciliation' => $reconciliation,
        ];
    }

    private function taxationGroup(?string $code): string
    {
        return match ($code) {
            '1' => 'taxable',
            '2' => 'immunity',
            '3' => 'export',
            '4' => 'non_incidence',
            default => 'unknown',
        };
    }

    private function retentionGroup(?string $code): string
    {
        return match ($code) {
            '1' => 'not_retained',
            '2', '3' => 'retained',
            default => 'unknown',
        };
    }

    /**
     * @param array<string,mixed>|null $snapshot
     * @param list<string> $path
     */
    private function snapshotString(?array $snapshot, array $path): ?string
    {
        $value = $snapshot;

        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param array<string,mixed>|null $snapshot
     * @param list<string> $path
     */
    private function snapshotMinor(?array $snapshot, array $path): ?int
    {
        $value = $this->snapshotString($snapshot, $path);

        return $value === null ? null : $this->decimalToMinor($value);
    }

    private function decimalToMinor(string $value): int
    {
        $normalized = str_replace(',', '.', trim($value));

        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
            return 0;
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);
        $minor = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$minor : $minor;
    }

    private function formatMinor(?int $minor): string
    {
        if ($minor === null) {
            return '';
        }

        $negative = $minor < 0;
        $minor = abs($minor);
        $formatted = intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? '-' . $formatted : $formatted;
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }
}
