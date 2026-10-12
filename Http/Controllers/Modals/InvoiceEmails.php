<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers\Modals;

use App\Abstracts\Http\Controller;
use App\Events\Document\DocumentSending;
use App\Events\Document\DocumentSent;
use App\Models\Document\Document as Invoice;
use App\Traits\Emails;
use Illuminate\Http\JsonResponse;
use Modules\Nfse\Application\PostEmissionEmailRecipients;
use Illuminate\Http\Request;
use Modules\Nfse\Http\Controllers\InvoiceController as NfseInvoiceController;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Notifications\NfseIssued;

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

        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->latest('id')->first()
            ?? new NfseReceipt(['invoice_id' => $invoice->id]);

        if (!$receipt instanceof NfseReceipt) {
            $receipt = new NfseReceipt(['invoice_id' => $invoice->id]);
        }

        $hasReceipt = $receipt->exists;
        $isEmitted = $hasReceipt && (string) ($receipt->status ?? '') === 'emitted';
        $isCancelled = $hasReceipt && (string) ($receipt->status ?? '') === 'cancelled';
        $submit_text = $isCancelled ? trans('nfse::general.invoices.reemit') : trans('nfse::general.invoices.emit_now');

        if ($isEmitted) {
            $cancel_route = 'nfse.invoices.cancel';
            $redirect_after_cancel = $this->cancelRedirectTarget($invoice, $request);

            $html = view('nfse::modals.invoices.cancel', compact(
                'invoice',
                'cancel_route',
                'redirect_after_cancel',
            ))->render();
        } else {
            $contacts = $invoice->contact->withPersons();
            $notification = new NfseIssued($invoice, $receipt);
            $preview = $this->issuePreviewData($invoice);
            $issue_route = $isCancelled ? 'nfse.invoices.reemit' : 'nfse.invoices.emit';

            $html = view('nfse::modals.invoices.issue', compact(
                'invoice',
                'contacts',
                'notification',
                'preview',
                'issue_route',
                'isCancelled',
            ))->render();
        }

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

        try {
            $recipients = (new PostEmissionEmailRecipients())->normalize(
                $request->input('to'),
                $request->input('cc'),
                $request->input('bcc'),
                filter_var($request->input('user_email', false), FILTER_VALIDATE_BOOLEAN)
                    ? (string) user()->email
                    : null,
            );
        } catch (\InvalidArgumentException $exception) {
            $labels = [
                'nfse_email_to' => 'emit_modal_email_to',
                'nfse_email_cc' => 'emit_modal_email_cc',
                'nfse_email_bcc' => 'emit_modal_email_bcc',
            ];
            $field = array_key_exists($exception->getMessage(), $labels)
                ? $exception->getMessage()
                : 'nfse_email_to';

            return new JsonResponse([
                'success' => false,
                'error' => true,
                'code' => 'nfse_email_invalid',
                'field' => $field,
                'message' => trans('nfse::general.invoices.emit_modal_email_invalid', [
                    'field' => trans('nfse::general.invoices.' . $labels[$field]),
                ]),
            ], 422);
        }

        // Match Akaunting's native send lifecycle: fiscal listeners run before
        // customer delivery and may persist the NFS-e that this email attaches.
        event(new DocumentSending($invoice));

        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->latest('id')->first();

        if (!$receipt instanceof NfseReceipt) {
            $receipt = null;
        }

        $attachInvoicePdf = (bool) $request->input('pdf', 1);
        $attachDanfse = (bool) $request->input('nfse_attach_danfse', 0);
        $attachXml    = (bool) $request->input('nfse_attach_xml', 0);

        $customMail = [
            'to'                 => $recipients['to'],
            'cc'                 => $recipients['cc'],
            'bcc'                => $recipients['bcc'],
            'subject'            => (string) $request->input('subject', ''),
            'body'               => (string) $request->input('body', ''),
            'attach_invoice_pdf' => $attachInvoicePdf,
        ];

        $job = new \Modules\Nfse\Jobs\SendNfseCustomEmail(
            $invoice,
            $receipt,
            $attachDanfse,
            $attachXml,
            $customMail,
        );

        $response = $this->sendEmail($job);

        if ($response['success']) {
            event(new DocumentSent($invoice));

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
