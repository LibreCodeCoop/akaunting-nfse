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

        $preflight = $this->preflight->evaluate($invoice);

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
        $simples = (int) $this->setting('nfse.opcao_simples_nacional', 2);
        $simples = in_array($simples, [1, 2], true) ? $simples : 2;
        $sandbox = filter_var($this->setting('nfse.sandbox_mode', true), FILTER_VALIDATE_BOOL);

        $federal = (new InvoiceFederalPayloadResolver(
            settingResolver: $this->settingResolver,
        ))->resolve($invoice, $itemIds, $amount);
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

        $baseDps = $this->dpsBuilder->build([
            'cnpjPrestador' => $cnpj,
            'municipioIbge' => $municipio,
            'itemListaServico' => (string) ($group['item_lista_servico'] ?? ''),
            'codigoTributacaoNacional' => (string) ($group['codigo_tributacao_nacional'] ?? ''),
            'codigoTributacaoMunicipal' => '',
            'valorServico' => number_format($amount, 2, '.', ''),
            'aliquota' => (string) ($group['aliquota'] ?? ''),
            'discriminacao' => $this->description($group),
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
            );

            return $result['receipt'];
        } finally {
            $context->close();
        }
    }

    private function setting(string $key, mixed $default): mixed
    {
        return ($this->settingResolver)($key, $default);
    }

    /** @param array<string,mixed> $group */
    private function description(array $group): string
    {
        $items = is_array($group['items'] ?? null) ? $group['items'] : [];

        return implode(' | ', array_values(array_filter(array_map(
            static fn (array $item): string => trim((string) ($item['name'] ?? '')),
            $items,
        ), static fn (string $name): bool => $name !== '')));
    }
}
