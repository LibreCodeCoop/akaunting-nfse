<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers\Modals;

use App\Abstracts\Http\Controller;
use App\Models\Document\Document as Invoice;
use App\Traits\Emails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Nfse\Http\Controllers\InvoiceController as NfseInvoiceController;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Notifications\NfseIssued;
use Modules\Nfse\Support\WebDavClient;

class InvoiceEmails extends Controller
{
    use Emails;

    public function __construct()
    {
        $this->middleware('permission:create-sales-invoices')->only('create', 'store', 'duplicate', 'import');
        $this->middleware('permission:read-sales-invoices')->only('index', 'show', 'edit', 'export');
        $this->middleware('permission:update-sales-invoices')->only('update', 'enable', 'disable');
        $this->middleware('permission:delete-sales-invoices')->only('destroy');
    }

    public function create(Invoice $invoice, ?Request $request = null): JsonResponse
    {
        $request ??= request();

        $contacts = $invoice->contact->withPersons();

        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->latest('id')->first()
            ?? new NfseReceipt(['invoice_id' => $invoice->id]);

        if (!$receipt instanceof NfseReceipt) {
            $receipt = new NfseReceipt(['invoice_id' => $invoice->id]);
        }

        $notification = new NfseIssued($invoice, $receipt);

        $hasReceipt   = $receipt->exists;
        $isEmitted    = $hasReceipt && (string) ($receipt->status ?? '') === 'emitted';
        $isCancelled  = $hasReceipt && (string) ($receipt->status ?? '') === 'cancelled';
        $hasDanfse    = $hasReceipt && $this->artifactAvailable($receipt, 'danfse');
        $hasXml       = $hasReceipt && $this->artifactAvailable($receipt, 'xml');
        $preview      = $this->issuePreviewData($invoice);

        $store_route = 'nfse.modals.invoices.emails.store';
        $cancel_route = 'nfse.invoices.cancel';
        $issue_route = $isCancelled ? 'nfse.invoices.reemit' : 'nfse.invoices.emit';
        $submit_text = $isCancelled ? trans('nfse::general.invoices.reemit') : trans('nfse::general.invoices.emit_now');
        $redirect_after_cancel = $this->cancelRedirectTarget($invoice, $request);

        $html = $isEmitted
            ? view('nfse::modals.invoices.cancel', compact(
                'invoice',
                'cancel_route',
                'redirect_after_cancel',
            ))->render()
            : view('nfse::modals.invoices.issue', compact(
                'invoice',
                'contacts',
                'notification',
                'preview',
                'issue_route',
                'isCancelled',
            ))->render();

        return response()->json([
            'success' => true,
            'error'   => false,
            'message' => 'null',
            'html'    => $html,
            'data'    => [
                'title'   => $isEmitted
                    ? trans('nfse::general.invoices.cancel_modal_title')
                    : trans('nfse::general.invoices.emit_modal_title'),
                'buttons' => [
                    'cancel'  => [
                        'text'  => trans('general.cancel'),
                        'class' => 'btn-outline-secondary',
                    ],
                    'confirm' => [
                        'text'  => $isEmitted ? trans('nfse::general.invoices.cancel_modal_submit') : $submit_text,
                        'class' => 'disabled:bg-green-100',
                    ],
                ],
            ],
        ]);
    }

    private function cancelRedirectTarget(Invoice $invoice, ?Request $request = null): string
    {
        $request ??= request();

        $requestedTarget = trim((string) $request->query('redirect_after_cancel', ''));

        if (in_array($requestedTarget, ['invoice_show', 'nfse_show', 'nfse_index'], true)) {
            return $requestedTarget;
        }

        $referer = trim((string) $request->header('referer', ''));

        $invoiceShowUrl = route('invoices.show', $invoice);
        $nfseShowUrl = route('nfse.invoices.show', $invoice);
        $nfseIndexUrl = route('nfse.invoices.index');

        $invoiceShowPath = (string) parse_url($invoiceShowUrl, PHP_URL_PATH);
        $nfseShowPath = (string) parse_url($nfseShowUrl, PHP_URL_PATH);
        $nfseIndexPath = (string) parse_url($nfseIndexUrl, PHP_URL_PATH);
        $refererPath = (string) parse_url($referer, PHP_URL_PATH);

        if (
            $referer === $invoiceShowUrl
            || str_starts_with($referer, $invoiceShowUrl . '?')
            || $refererPath === $invoiceShowPath
            || str_starts_with($refererPath, $invoiceShowPath . '/')
        ) {
            return 'invoice_show';
        }

        if (
            $referer === $nfseShowUrl
            || str_starts_with($referer, $nfseShowUrl . '?')
            || $refererPath === $nfseShowPath
            || str_starts_with($refererPath, $nfseShowPath . '/')
        ) {
            return 'nfse_show';
        }

        if (
            $referer === $nfseIndexUrl
            || str_starts_with($referer, $nfseIndexUrl . '?')
            || $refererPath === $nfseIndexPath
            || str_starts_with($refererPath, $nfseIndexPath . '/')
        ) {
            return 'nfse_index';
        }

        return 'invoice_show';
    }

    public function store(Request $request): JsonResponse
    {
        $invoice = Invoice::findOrFail($request->input('document_id'));

        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->latest('id')->first();

        if (!$receipt instanceof NfseReceipt) {
            $receipt = null;
        }

        $attachInvoicePdf = (bool) $request->input('pdf', 1);
        $attachDanfse = (bool) $request->input('nfse_attach_danfse', 0);
        $attachXml    = (bool) $request->input('nfse_attach_xml', 0);

        $customMail = [
            'to'                 => $request->input('to', []),
            'subject'            => (string) $request->input('subject', ''),
            'body'               => (string) $request->input('body', ''),
            'attach_invoice_pdf' => $attachInvoicePdf,
        ];

        if ($request->input('user_email')) {
            $customMail['bcc'] = user()->email;
        }

        $job = new \Modules\Nfse\Jobs\SendNfseCustomEmail(
            $invoice,
            $receipt,
            $attachDanfse,
            $attachXml,
            $customMail,
        );

        $response = $this->sendEmail($job);

        if ($response['success']) {
            $route = config('type.document.' . $invoice->type . '.route.prefix');

            if ($alias = config('type.document.' . $invoice->type . '.alias')) {
                $route = $alias . '.' . $route;
            }

            $response['redirect'] = route($route . '.show', $invoice->id);

            $message = trans('documents.messages.email_sent', ['type' => trans_choice('general.invoices', 1)]);

            flash($message)->success();
        } else {
            $response['redirect'] = null;
        }

        return response()->json($response);
    }

    private function artifactAvailable(NfseReceipt $receipt, string $type): bool
    {
        $field = $type === 'danfse' ? 'danfse_webdav_path' : 'xml_webdav_path';
        $path  = trim((string) ($receipt->{$field} ?? ''));

        if ($path === '') {
            return false;
        }

        try {
            return (new WebDavClient(
                baseUrl: (string) setting('nfse.webdav_url', ''),
                username: (string) setting('nfse.webdav_username', ''),
                password: (string) setting('nfse.webdav_password', ''),
            ))->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function issuePreviewData(Invoice $invoice): array
    {
        try {
            $response = app(NfseInvoiceController::class)->servicePreview($invoice);
            $data = $response->getData(true);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
