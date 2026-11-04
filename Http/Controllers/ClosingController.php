<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Nfse\Application\CommercialFiscalReviewReport;
use Modules\Nfse\Application\CompetenceClosingReport;
use Modules\Nfse\Models\NfseReceipt;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ClosingController extends Controller
{
    public function index(Request $request): View
    {
        abort_if((int) company_id() <= 0, 403, 'An active company is required for fiscal closing.');
        // The view is presentation only. All fiscal data comes from JSON.
        $competence = $this->competence($request);

        return view('nfse::closing.index', ['competence' => $competence]);
    }

    /** The same report services feed external integrations and Web administration. */
    public function data(Request $request, CompetenceClosingReport $report): JsonResponse
    {
        $competence = $this->competence($request);
        $page = $request->query('report_page', 1);
        if (!is_scalar($page) || preg_match('/^[1-9]\d*$/', (string) $page) !== 1
            || (int) $page > 100000) {
            return response()->json(['message' => 'Invalid report page.'], 422);
        }

        $closing = $report->build($this->receipts($competence));
        $commercialReview = (new CommercialFiscalReviewReport())->forInvoiceMonth((int) company_id(), $competence);
        $total = count($closing['rows']);
        $perPage = 50;
        $pageNumber = (int) $page;

        return response()->json([
            'data' => [
                'competence' => $competence,
                'summary' => $closing['summary'],
                'reconciliation' => $closing['reconciliation'],
                'rows' => array_slice($closing['rows'], ($pageNumber - 1) * $perPage, $perPage),
                'commercial_review' => [
                    'rows' => $commercialReview['rows'],
                    'meta' => [
                        'current_page' => $commercialReview['page']->currentPage(),
                        'last_page' => $commercialReview['page']->lastPage(),
                        'per_page' => $commercialReview['page']->perPage(),
                        'total' => $commercialReview['page']->total(),
                    ],
                ],
            ],
            'meta' => [
                'current_page' => $pageNumber,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function export(Request $request, CompetenceClosingReport $report): StreamedResponse
    {
        $competence = $this->competence($request);
        $closing = $report->build($this->receipts($competence));
        $columns = $this->csvColumns();

        return response()->streamDownload(function () use ($closing, $columns): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }

            fputcsv($stream, array_keys($columns));

            foreach ($closing['rows'] as $row) {
                fputcsv($stream, array_map(
                    static fn (string $key): string => (string) ($row[$key] ?? ''),
                    array_values($columns),
                ));
            }

            fclose($stream);
        }, 'nfse-closing-' . $competence . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function competence(Request $request): string
    {
        $competence = trim((string) $request->query('competence', date('Y-m')));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competence) !== 1) {
            abort(422, 'Invalid competence. Expected YYYY-MM.');
        }

        return $competence;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int,NfseReceipt>
     */
    private function receipts(string $competence): \Illuminate\Database\Eloquent\Collection
    {
        $start = $competence . '-01';
        $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $companyId = function_exists('company_id') ? (int) company_id() : 0;

        if ($companyId <= 0) {
            abort(403, 'An active company is required for fiscal closing.');
        }

        return NfseReceipt::query()
            ->with('invoice')
            ->whereBetween('competence_date', [$start, $end])
            ->whereHas('invoice', static function ($invoiceQuery) use ($companyId): void {
                $invoiceQuery->where('company_id', $companyId);
            })
            ->orderBy('competence_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * CSV labels are intentionally stable machine-facing keys.
     *
     * @return array<string,string>
     */
    private function csvColumns(): array
    {
        return [
            'access_key' => 'access_key',
            'nfse_number' => 'nfse_number',
            'invoice_number' => 'invoice_number',
            'customer' => 'customer',
            'competence' => 'competence',
            'status' => 'status',
            'gross_service_value' => 'gross_service_value',
            'liquid_value' => 'liquid_value',
            'taxation_group' => 'taxation_group',
            'iss_retention' => 'iss_retention',
            'issqn_value' => 'issqn_value',
            'pis' => 'pis',
            'cofins' => 'cofins',
            'irrf' => 'irrf',
            'social_security' => 'social_security',
            'csll' => 'csll',
            'ibs_total' => 'ibs_total',
            'cbs_total' => 'cbs_total',
            'nfse_total' => 'nfse_total',
            'source_sha256' => 'source_sha256',
            'reconciliation_status' => 'reconciliation_status',
        ];
    }
}
