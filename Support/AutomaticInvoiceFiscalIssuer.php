<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Document\Document as Invoice;
use Modules\Nfse\Application\AutomaticInvoiceEmissionPreflight;
use Modules\Nfse\Application\IbsCbsPayloadResolver;
use Modules\Nfse\Application\InvoiceDpsBuilder;
use Modules\Nfse\Application\InvoiceDpsIdentity;
use Modules\Nfse\Application\IssqnPayloadResolver;
use Modules\Nfse\Application\IssueInvoiceFiscalGroup;
use Modules\Nfse\Application\ReceiptPersistence;
use Modules\Nfse\Contracts\BulkEmissionUnitIssuerInterface;
use Modules\Nfse\Models\NfseReceipt;

final class AutomaticInvoiceFiscalIssuer implements BulkEmissionUnitIssuerInterface
{
    /** @var \Closure(bool):FiscalClientContext */
    private readonly \Closure $clientContextFactory;

    /** @var \Closure(string,mixed):mixed */
    private readonly \Closure $settingResolver;

    public function __construct(
        private readonly ?FiscalClientFactory $clientFactory = null,
        private readonly AutomaticInvoiceEmissionPreflight $preflight = new AutomaticInvoiceEmissionPreflight(),
        private readonly InvoiceTakerResolver $taker = new InvoiceTakerResolver(),
        private readonly InvoiceDpsBuilder $dpsBuilder = new InvoiceDpsBuilder(),
        private readonly InvoiceDpsIdentity $identity = new InvoiceDpsIdentity(),
        private readonly IssueInvoiceFiscalGroup $groupIssuer = new IssueInvoiceFiscalGroup(),
        private readonly ReceiptPersistence $receipts = new ReceiptPersistence(),
        ?\Closure $clientContextFactory = null,
        ?\Closure $settingResolver = null,
    ) {
        $this->settingResolver = $settingResolver
            ?? static fn (string $key, mixed $default): mixed => function_exists('setting')
                ? \setting($key, $default)
                : $default;
        $this->clientContextFactory = $clientContextFactory
            ?? function (bool $sandbox): FiscalClientContext {
                if (!$this->clientFactory instanceof FiscalClientFactory) {
                    throw new \LogicException('Fiscal client factory is required.');
                }

                return $this->clientFactory->nfse($sandbox);
            };
    }

    public function issue(int $invoiceId, string $emissionGroupKey): NfseReceipt
    {
        if ($invoiceId <= 0 || trim($emissionGroupKey) === '') {
            throw new \InvalidArgumentException('Invoice and fiscal group are required.');
        }

        // Job payloads identify an invoice, but never grant tenant access.
        // Enforce company context before reusing a pre-existing receipt.
        $companyId = function_exists('company_id') ? (int) company_id() : 0;
        if ($companyId > 0 && !Invoice::query()
            ->whereKey($invoiceId)
            ->where('company_id', $companyId)
            ->where('type', 'invoice')
            ->exists()) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Cannot issue another company invoice.');
        }

        $existing = $this->receipts->findGrouped($invoiceId, $emissionGroupKey);

        if ($existing instanceof NfseReceipt) {
            return $existing;
        }

        $invoice = Invoice::query()
            ->whereKey($invoiceId)
            ->where('type', 'invoice')
            ->first();

        if (!$invoice instanceof Invoice) {
            throw new \InvalidArgumentException('Invoice not found.');
        }

        $preflight = $this->preflight->evaluate($invoice, $this->nfseSettings());

        if (($preflight['status'] ?? '') !== 'ready' || !is_array($preflight['group'] ?? null)) {
            throw new \LogicException(
                'Automatic NFS-e preflight blocked issuance: '
                . trim((string) ($preflight['reason'] ?? 'preflight_blocked')),
            );
        }

        $group = $preflight['group'];
        $groupKey = trim((string) ($group['key'] ?? ''));

        if ($groupKey === '' || !hash_equals($groupKey, $emissionGroupKey)) {
            throw new \LogicException('Fiscal group changed after preflight.');
        }

        $cnpj = preg_replace('/\D+/', '', (string) $this->setting('nfse.cnpj_prestador', '')) ?: '';
        $municipio = preg_replace('/\D+/', '', (string) $this->setting('nfse.municipio_ibge', '')) ?: '';

        if (strlen($cnpj) !== 14 || strlen($municipio) !== 7) {
            throw new \LogicException('Service provider CNPJ and municipality must be configured.');
        }

        $itemIds = array_values(array_filter(array_map(
            static fn (array $item): int => is_numeric($item['document_item_id'] ?? null)
                ? (int) $item['document_item_id']
                : 0,
            is_array($group['items'] ?? null) ? $group['items'] : [],
        ), static fn (int $id): bool => $id > 0));
        $amount = (float) ($group['amount'] ?? 0);
        $simples = (int) $this->setting('nfse.opcao_simples_nacional', 1);
        $simples = in_array($simples, [1, 2, 3], true) ? $simples : 1;
        $sandbox = filter_var($this->setting('nfse.sandbox_mode', true), FILTER_VALIDATE_BOOL);

        $federal = (new InvoiceFederalPayloadResolver(
            settingResolver: $this->settingResolver,
        ))->resolve($invoice, $itemIds, $amount, $group['aliquota'] ?? null);
        $issqn = (new IssqnPayloadResolver())->resolve([
            'tributacao_issqn' => $this->setting('nfse.tributacao_issqn', 1),
            'tipo_retencao_iss' => $this->setting('nfse.tipo_retencao_iss', 1),
            'issqn_pais_resultado' => $this->setting('nfse.issqn_pais_resultado', ''),
            'issqn_tipo_imunidade' => $this->setting('nfse.issqn_tipo_imunidade', ''),
            'issqn_tipo_suspensao' => $this->setting('nfse.issqn_tipo_suspensao', ''),
            'issqn_numero_processo_suspensao' => $this->setting('nfse.issqn_numero_processo_suspensao', ''),
        ]);
        $ibsCbs = (new IbsCbsPayloadResolver())->resolve([
            'enabled' => $this->setting('nfse.ibs_cbs_enabled', false),
            'ind_final' => $this->setting('nfse.ibs_cbs_ind_final', ''),
            'ind_dest' => $this->setting('nfse.ibs_cbs_ind_dest', ''),
            'c_ind_op' => $this->setting('nfse.ibs_cbs_c_ind_op', ''),
            'cst' => $this->setting('nfse.ibs_cbs_cst', ''),
            'c_class_trib' => $this->setting('nfse.ibs_cbs_c_class_trib', ''),
        ]);

        $providerContact = $this->providerContact();

        $baseDps = $this->dpsBuilder->build([
            'cnpjPrestador' => $cnpj,
            'municipioIbge' => $municipio,
            'prestadorTelefone' => $providerContact['telefone'],
            'prestadorEmail' => $providerContact['email'],
            'itemListaServico' => (string) ($group['item_lista_servico'] ?? ''),
            'codigoTributacaoNacional' => (string) ($group['codigo_tributacao_nacional'] ?? ''),
            'codigoTributacaoMunicipal' => (string) ($group['codigo_tributacao_municipal'] ?? ''),
            'codigoNbs' => (string) ($group['codigo_nbs'] ?? ''),
            'valorServico' => number_format($amount, 2, '.', ''),
            'aliquota' => (string) ($group['aliquota'] ?? ''),
            'discriminacao' => $this->description($group, $invoice),
            'documentoTomador' => $this->taker->document($invoice),
            'nomeTomador' => $this->taker->name($invoice),
            'tomador' => $this->taker->payload($invoice->contact ?? null, $invoice),
            'foreignTomador' => ['enabled' => false],
            'opcaoSimplesNacional' => $simples,
            'issqn' => $issqn,
            'tipoAmbiente' => $sandbox ? 2 : 1,
            'serie' => $this->identity->series($invoice),
            'numeroDps' => $this->identity->number($invoice),
            'dataCompetencia' => $this->identity->competenceDate($invoice),
            'federal' => $federal,
            'ibsCbs' => $ibsCbs,
        ]);

        $context = ($this->clientContextFactory)($sandbox);

        try {
            $result = $this->groupIssuer->issue(
                $context->nfseClient(),
                $invoiceId,
                $baseDps,
                $group,
                'automatic',
                descriptionOverride: $this->description($group, $invoice),
            );

            return $result['receipt'];
        } finally {
            $context->close();
        }
    }

    /** @return array{telefone:string,email:string} */
    private function providerContact(): array
    {
        try {
            $company = function_exists('company') ? \company() : null;
        } catch (\Throwable) {
            $company = null;
        }

        $phone = is_object($company) ? trim((string) ($company->phone ?? '')) : '';
        $email = is_object($company) ? trim((string) ($company->email ?? '')) : '';

        return [
            'telefone' => preg_replace('/\D+/', '', $phone) ?: '',
            'email' => $email,
        ];
    }

    /** @return array<string,mixed> */
    private function nfseSettings(): array
    {
        return [
            'opcao_simples_nacional' => $this->setting('nfse.opcao_simples_nacional', 1),
            'ibs_cbs_enabled' => $this->setting('nfse.ibs_cbs_enabled', false),
            'ibs_cbs_ind_final' => $this->setting('nfse.ibs_cbs_ind_final', ''),
            'ibs_cbs_ind_dest' => $this->setting('nfse.ibs_cbs_ind_dest', ''),
            'ibs_cbs_c_ind_op' => $this->setting('nfse.ibs_cbs_c_ind_op', ''),
            'ibs_cbs_cst' => $this->setting('nfse.ibs_cbs_cst', ''),
            'ibs_cbs_c_class_trib' => $this->setting('nfse.ibs_cbs_c_class_trib', ''),
            'enforce_item_federal_taxes' => $this->setting('nfse.enforce_item_federal_taxes', true),
            'federal_piscofins_situacao_tributaria' => $this->setting(
                'nfse.federal_piscofins_situacao_tributaria',
                '',
            ),
            'federal_piscofins_tipo_retencao' => $this->setting(
                'nfse.federal_piscofins_tipo_retencao',
                '',
            ),
        ];
    }

    private function setting(string $key, mixed $default): mixed
    {
        return ($this->settingResolver)($key, $default);
    }

    /** @param array<string,mixed> $group */
    private function description(array $group, Invoice $invoice): string
    {
        $items = is_array($group['items'] ?? null) ? $group['items'] : [];
        $service = implode(' | ', array_values(array_filter(array_map(
            static fn (array $item): string => trim((string) ($item['name'] ?? '')),
            $items,
        ), static fn (string $name): bool => $name !== '')));

        // Akaunting stores invoice-specific observations in Document.notes.
        $notes = trim(str_replace(
            ['\r\n', '\n', '\r', "\r\n", "\r"],
            ["\n", "\n", "\n", "\n", "\n"],
            (string) ($invoice->notes ?? ''),
        ));

        return implode("\n\n", array_values(array_unique(array_filter(
            [$service, $notes],
            static fn (string $part): bool => $part !== '',
        ))));
    }
}
