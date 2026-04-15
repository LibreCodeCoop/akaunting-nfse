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
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Notifications\NfseIssued;
use Modules\Nfse\Support\WebDavClient;

class InvoiceEmails extends Controller
{
    use Emails;

    public function create(Invoice $invoice): JsonResponse
    {
        $contacts = $invoice->contact->withPersons();

        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->latest('id')->first()
            ?? new NfseReceipt(['invoice_id' => $invoice->id]);

        $notification = new NfseIssued($invoice, $receipt);

        $hasReceipt   = $receipt->exists;
        $hasDanfse    = $hasReceipt && $this->artifactAvailable($receipt, 'danfse');
        $hasXml       = $hasReceipt && $this->artifactAvailable($receipt, 'xml');

        $store_route = 'nfse.modals.invoices.emails.store';

        $html = view('nfse::modals.invoices.email', compact(
            'invoice',
            'contacts',
            'notification',
            'store_route',
            'hasReceipt',
            'hasDanfse',
            'hasXml',
        ))->render();

        return response()->json([
            'success' => true,
            'error'   => false,
            'message' => 'null',
            'html'    => $html,
            'data'    => [
                'title'   => trans('general.title.new', ['type' => trans_choice('general.email', 1)]),
                'buttons' => [
                    'cancel'  => [
                        'text'  => trans('general.cancel'),
                        'class' => 'btn-outline-secondary',
                    ],
                    'confirm' => [
                        'text'  => trans('general.send'),
                        'class' => 'disabled:bg-green-100',
                    ],
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $invoice = Invoice::findOrFail($request->input('document_id'));

        $receipt = NfseReceipt::where('invoice_id', $invoice->id)->latest('id')->first();

        $attachDanfse = (bool) $request->input('nfse_attach_danfse', 0);
        $attachXml    = (bool) $request->input('nfse_attach_xml', 0);

        $customMail = [
            'to'      => $request->input('to', []),
            'subject' => (string) $request->input('subject', ''),
            'body'    => (string) $request->input('body', ''),
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
            return (new WebDavClient())->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }
}
