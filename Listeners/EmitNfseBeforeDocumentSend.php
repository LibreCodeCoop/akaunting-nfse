<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Models\Document\Document;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\AutomaticInvoiceEmissionPreflight;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Support\NfseCompanyFiscalSettings;
use Modules\Nfse\Support\NfseCompanyJobContext;

/**
 * Makes emit-on-send a fail-closed capability of Akaunting's native send job.
 *
 * DocumentSending is dispatched before Akaunting notifies the customer. Any
 * preflight or fiscal issuance exception therefore prevents customer delivery
 * from falsely implying that an NFS-e was authorized.
 */
final class EmitNfseBeforeDocumentSend
{
    /** @var (\Closure(string,mixed):mixed)|null */
    private readonly ?\Closure $settingResolver;

    public function __construct(
        private readonly AutomaticInvoiceEmissionPreflight $preflight,
        private readonly BulkEmissionUnitIssuerInterface $issuer,
        ?\Closure $settingResolver = null,
    ) {
        // An explicit resolver is for deterministic isolated tests. Production
        // reads only the owning company's NFS-e profile.
        $this->settingResolver = $settingResolver;
    }

    /** @return array<string,mixed> */
    private function nfseSettings(int $companyId): array
    {
        if ($this->settingResolver === null) {
            return (new NfseCompanyFiscalSettings())->all($companyId);
        }

        $keys = [
            'opcao_simples_nacional',
            'ibs_cbs_enabled',
            'ibs_cbs_ind_final',
            'ibs_cbs_ind_dest',
            'ibs_cbs_c_ind_op',
            'ibs_cbs_cst',
            'ibs_cbs_c_class_trib',
            'enforce_item_federal_taxes',
            'federal_piscofins_situacao_tributaria',
            'federal_piscofins_tipo_retencao',
        ];
        $settings = [];

        $defaults = [
            'opcao_simples_nacional' => null,
            'ibs_cbs_enabled' => false,
            'ibs_cbs_ind_final' => '',
            'ibs_cbs_ind_dest' => '',
            'ibs_cbs_c_ind_op' => '',
            'ibs_cbs_cst' => '',
            'ibs_cbs_c_class_trib' => '',
            'enforce_item_federal_taxes' => true,
            'federal_piscofins_situacao_tributaria' => '',
            'federal_piscofins_tipo_retencao' => '',
        ];

        foreach ($keys as $key) {
            $settings[$key] = ($this->settingResolver)('nfse.' . $key, $defaults[$key]);
        }

        return $settings;
    }

    public function handle(object $event): void
    {
        $document = $event->document ?? null;

        if (!$document instanceof Document || (string) ($document->type ?? '') !== 'invoice') {
            return;
        }

        $companyId = (int) ($document->company_id ?? 0);
        $activeCompany = function_exists('company_id') ? (int) company_id() : 0;
        if ($companyId <= 0 || ($activeCompany > 0 && $activeCompany !== $companyId)) {
            throw new AuthorizationException('Cannot emit another company invoice.');
        }

        if ($activeCompany === 0) {
            // Only an unauthenticated background worker may recover a missing
            // context from the actual persisted invoice. An HTTP actor must
            // select an authorized company before requesting fiscal issuance.
            if (!app()->runningInConsole() || auth()->check()
                || !DB::table('documents')->where('id', (int) $document->id)
                    ->where('type', 'invoice')->where('company_id', $companyId)->exists()) {
                throw new AuthorizationException('Fiscal invoice has no verified company context.');
            }

            (new NfseCompanyJobContext())->run(
                $companyId,
                fn (): mixed => $this->emitForCompany($document, $companyId),
            );

            return;
        }

        $this->emitForCompany($document, $companyId);
    }

    private function emitForCompany(Document $document, int $companyId): void
    {
        try {
            $settings = $this->nfseSettings($companyId);
        } catch (\DomainException) {
            // No active NFS-e profile means this is a normal Akaunting send,
            // never an implicit request to issue under another company's data.
            return;
        }

        $policy = strtolower(trim((string) ($settings['emission_policy'] ?? 'manual')));
        if ($policy !== 'emit_on_send') {
            return;
        }

        $result = $this->preflight->evaluate($document, $settings);
        $status = (string) ($result['status'] ?? 'blocked');

        if ($status === 'already_issued') {
            return;
        }

        if ($status !== 'ready' || !is_array($result['group'] ?? null)) {
            $reason = trim((string) ($result['reason'] ?? 'preflight_blocked'));

            throw new \LogicException(
                'Automatic NFS-e emission blocked before document send: ' . $reason,
            );
        }

        $groupKey = trim((string) ($result['group']['key'] ?? ''));

        if ($groupKey === '') {
            throw new \LogicException(
                'Automatic NFS-e emission blocked before document send: missing_fiscal_group',
            );
        }

        $this->issuer->issue((int) $document->id, $groupKey);
    }
}
