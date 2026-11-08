<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Events\Common\SearchStringApplying;
use App\Models\Document\Document;
use Modules\Nfse\Application\CategoryIssuancePolicy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Apply NFS-e search chips before Akaunting paginates. The virtual search key
 * must not reach the native SQL search-string visitor, which only understands
 * physical document columns.
 */
final class ApplyNativeInvoiceFiscalFilter
{
    public const ORIGINAL_SEARCH = 'nfse.invoice_search.original';

    private const STATUSES = ['pending', 'processing', 'emitted', 'cancelled', 'substituted', 'absent', 'unknown', 'do_not_issue', 'not_applicable'];

    public function handle(SearchStringApplying $event): void
    {
        if (!request()->routeIs('invoices.index')) {
            return;
        }

        $query = $event->query;
        if (!$query instanceof Builder || !$query->getModel() instanceof Document) {
            return;
        }

        $search = request()->input('search', '');
        if (!is_string($search)) {
            return;
        }

        // Legacy bookmarks keep working, but the selected status now appears
        // in the same search chips as all native document filters.
        $legacy = request()->query('nfse_status');
        if (is_string($legacy) && in_array($legacy, self::STATUSES, true)
            && !preg_match('/(?:^|\s)(?:not\s+)?nfse_status\s*(?::|=|!=)/', $search)) {
            $search = trim($search . ' nfse_status:' . $legacy);
        }

        $values = [];
        $negated = false;
        $remaining = preg_replace_callback(
            '/(?:^|\s)(not\s+)?nfse_status\s*(:|=|!=)\s*([a-z_,\-]+)/i',
            static function (array $match) use (&$values, &$negated): string {
                $requested = array_map('strtolower', explode(',', $match[3]));
                if ($requested === [] || array_diff($requested, self::STATUSES) !== []) {
                    // Do not let an invalid virtual column reach the SQL parser.
                    return ' ';
                }

                // A second fiscal token would be ambiguous; apply the first one.
                if ($values === []) {
                    $values = array_values(array_unique($requested));
                    $negated = !empty($match[1]) || $match[2] === '!=';
                }

                return ' ';
            },
            $search,
        );

        if ($remaining === null) {
            return;
        }

        $remaining = trim(preg_replace('/\s+/', ' ', $remaining) ?? '');
        if ($values === [] && $remaining === $search) {
            return;
        }

        request()->attributes->set(self::ORIGINAL_SEARCH, $search);
        request()->merge(['search' => $remaining]);

        // The first tab defaults to unpaid when no search is present. Fiscal
        // filtering without a commercial status must instead span all invoices.
        if (request()->input('programmatic') === '1' && $values !== []) {
            request()->merge(['search' => '', 'list_records' => 'all']);
        }

        if ($values === []) {
            return;
        }

        $table = $query->getModel()->getTable();
        $authorized = (new CategoryIssuancePolicy())->authorizedInvoiceSql();
        $latest = '(SELECT r.status FROM nfse_receipts r WHERE r.invoice_id = ' . $table
            . '.id ORDER BY r.id DESC LIMIT 1)';
        $decision = '(SELECT d.decision FROM nfse_invoice_decisions d WHERE d.invoice_id = '
            . $table . '.id AND d.company_id = ' . $table
            . '.company_id ORDER BY d.id DESC LIMIT 1)';
        $categoryPolicy = '(SELECT p.mode FROM nfse_category_issuance_policies p'
            . ' WHERE p.company_id = ' . $table . '.company_id'
            . ' AND p.category_id = ' . $table . '.category_id'
            . ' AND p.recorded_at <= ' . $table . '.created_at'
            . ' AND DATE(p.recorded_at) <= DATE(' . $table . '.issued_at)'
            . ' ORDER BY p.id DESC LIMIT 1)';
        $effective = '(CASE WHEN ' . $decision . " IN ('do_not_issue', 'not_applicable')"
            . ' THEN ' . $decision
            . " WHEN " . $decision . " = 'issue' THEN NULL"
            . ' ELSE ' . $categoryPolicy . ' END)';

        $clauses = [];
        $bindings = [];

        foreach ($values as $status) {
            if (in_array($status, ['do_not_issue', 'not_applicable'], true)) {
                $clauses[] = '(' . $latest . ' IS NULL AND ' . $authorized . ' AND ' . $effective . ' = ?)';
                $bindings[] = $status;
            } elseif ($status === 'absent') {
                $clauses[] = '(' . $latest . ' IS NULL AND ' . $authorized . ')';
            } elseif ($status === 'pending') {
                // An authorized, not-yet-issued invoice is pending even without
                // a receipt row. Unregistered/non-service categories are not.
                $clauses[] = '(' . $latest . ' = ? OR ('
                    . $latest . ' IS NULL AND ' . $authorized
                    . ' AND COALESCE(' . $effective . ", 'issue') = 'issue'))";
                $bindings[] = 'pending';
            } elseif ($status === 'unknown') {
                $clauses[] = '(' . $latest . ' IS NOT NULL AND ' . $latest . ' NOT IN (?, ?, ?, ?, ?))';
                array_push($bindings, 'pending', 'processing', 'emitted', 'cancelled', 'substituted');
            } else {
                $clauses[] = $latest . ' = ?';
                $bindings[] = $status;
            }
        }

        $sql = '(' . implode(' OR ', $clauses) . ')';
        if ($negated) {
            // Restrict the fiscal facet to authorized categories or existing
            // receipt evidence, even for the negation of an issued status.
            $sql = '((' . $authorized . ' OR ' . $latest
                . ' IS NOT NULL) AND NOT COALESCE(' . $sql . ', 0))';
        }

        $query->whereRaw($sql, $bindings);
    }
}
