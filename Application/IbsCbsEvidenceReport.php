<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\ExactTaxDecimal;

/**
 * Read-only evidence register derived exclusively from stored authorized-XML
 * snapshots. This is neither an IBS/CBS tax assessment nor an official return.
 *
 * Missing fields are NOT zero, and an XML snapshot does NOT prove that a field
 * was legally optional or that the federal tax obligation was discharged.
 */
final class IbsCbsEvidenceReport
{
    /**
     * @param iterable<object> $receipts Already limited to one authorized company and fiscal period by the caller.
     * @return array{rows:list<array<string,int|string>>,summary:array<string,mixed>}
     */
    public function build(iterable $receipts): array
    {
        $rows = [];
        $summary = [
            'active_documents' => 0,
            'excluded_documents' => 0,
            'missing_snapshots' => 0,
            'duplicate_documents' => 0,
            'ibs' => $this->emptyTaxSummary(),
            'cbs' => $this->emptyTaxSummary(),
        ];
        $totals = ['ibs' => 0, 'cbs' => 0];
        $seenSources = [];

        foreach ($receipts as $receipt) {
            $rawStatus = trim((string) ($receipt->status ?? ''));
            // Keep spreadsheet-facing metadata inert even for malformed legacy records.
            $status = preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $rawStatus) === 1
                ? $rawStatus : 'unknown';
            $active = $status === 'emitted';
            $snapshot = is_array($receipt->authorized_fiscal_snapshot ?? null)
                ? $receipt->authorized_fiscal_snapshot
                : null;
            $source = $snapshot['source'] ?? null;
            $hash = $snapshot['source_sha256'] ?? null;
            $recorded = $source === 'authorized_xml'
                && is_string($hash)
                && preg_match('/^[0-9a-f]{64}$/Di', $hash) === 1;
            $normalizedHash = $recorded ? strtolower($hash) : '';
            $duplicate = $active && $recorded && isset($seenSources[$normalizedHash]);
            if ($active && $recorded && !$duplicate) {
                $seenSources[$normalizedHash] = true;
            }

            $row = [
                'receipt_id' => (int) ($receipt->id ?? 0),
                'invoice_id' => (int) ($receipt->invoice_id ?? 0),
                'competence' => $this->dateString($receipt->competence_date ?? null),
                'receipt_status' => $status,
                'source_sha256' => $recorded ? $hash : '',
            ];

            if ($active) {
                $summary['active_documents']++;
                if (!$recorded) {
                    $summary['missing_snapshots']++;
                } elseif ($duplicate) {
                    $summary['duplicate_documents']++;
                }
            } else {
                $summary['excluded_documents']++;
            }

            foreach (['ibs' => 'ibs_total', 'cbs' => 'cbs_total'] as $tax => $field) {
                $money = null;
                $evidenceStatus = 'excluded_non_active';

                if ($active && !$recorded) {
                    $evidenceStatus = 'snapshot_unavailable';
                    $summary[$tax]['not_reported_count']++;
                } elseif ($active && $duplicate) {
                    $evidenceStatus = 'duplicate_source';
                } elseif ($active) {
                    $raw = is_array($snapshot['ibs_cbs'] ?? null)
                        ? ($snapshot['ibs_cbs'][$field] ?? null)
                        : null;

                    if ($raw === null || $raw === '') {
                        $evidenceStatus = 'not_reported';
                        $summary[$tax]['not_reported_count']++;
                    } else {
                        try {
                            if (!is_string($raw) || preg_match('/^-?\d{1,15}(?:\.\d{1,2})?$/D', $raw) !== 1) {
                                throw new \DomainException('Unsupported tax evidence precision.');
                            }
                            $money = ExactTaxDecimal::cents($raw);
                            if ($money < 0) {
                                throw new \DomainException('A negative reported tax total needs an explicit fiscal adjustment record.');
                            }
                        } catch (\DomainException | \OverflowException) {
                            $money = null;
                            $evidenceStatus = 'invalid_amount';
                            $summary[$tax]['invalid_count']++;
                        }

                        if ($money !== null) {
                            if ($money > 0 && $totals[$tax] > PHP_INT_MAX - $money) {
                                throw new \OverflowException('Fiscal evidence total exceeds integer-cent capacity.');
                            }
                            if ($money < 0 && $totals[$tax] < PHP_INT_MIN - $money) {
                                throw new \OverflowException('Fiscal evidence total exceeds integer-cent capacity.');
                            }
                            $totals[$tax] += $money;
                            $summary[$tax]['reported_count']++;
                            $evidenceStatus = $money === 0 ? 'reported_zero' : 'reported_amount';
                        }
                    }
                }

                $row[$tax . '_amount'] = $money === null ? '' : ExactTaxDecimal::formatCents($money);
                $row[$tax . '_status'] = $evidenceStatus;
            }

            $rows[] = $row;
        }

        foreach (['ibs', 'cbs'] as $tax) {
            $summary[$tax]['reported_total'] = ExactTaxDecimal::formatCents($totals[$tax]);
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /** @return array{reported_total:string,reported_count:int,not_reported_count:int,invalid_count:int} */
    private function emptyTaxSummary(): array
    {
        return [
            'reported_total' => '0.00',
            'reported_count' => 0,
            'not_reported_count' => 0,
            'invalid_count' => 0,
        ];
    }

    private function dateString(mixed $date): string
    {
        $value = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : trim((string) $date);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 ? $value : '';
    }
}
