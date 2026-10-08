<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use App\Models\Document\Document as Invoice;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\FederalTaxSnapshotBuilder;

/**
 * Resolves federal fiscal payload from an Akaunting invoice without HTTP state.
 *
 * The class owns Akaunting persistence/settings adaptation; federal tax
 * classification/math remains in the pure FederalTaxSnapshotBuilder.
 */
final class InvoiceFederalPayloadResolver
{
    /** @var \Closure(string,mixed):mixed */
    private readonly \Closure $settingResolver;

    /** @var \Closure(int):?float */
    private readonly \Closure $taxRateResolver;

    /**
     * @param (\Closure(string,mixed):mixed)|null $settingResolver
     * @param (\Closure(int):?float)|null $taxRateResolver
     */
    public function __construct(
        private readonly FederalTaxSnapshotBuilder $snapshotBuilder = new FederalTaxSnapshotBuilder(),
        ?\Closure $settingResolver = null,
        ?\Closure $taxRateResolver = null,
    ) {
        $this->settingResolver = $settingResolver
            ?? static fn (string $key, mixed $default): mixed => function_exists('setting')
                ? \setting($key, $default)
                : $default;

        $this->taxRateResolver = $taxRateResolver
            ?? static function (int $taxId): ?float {
                try {
                    $rate = DB::table('taxes')->where('id', $taxId)->value('rate');
                } catch (\Throwable) {
                    return null;
                }

                if (!is_numeric($rate)) {
                    return null;
                }

                $normalized = (float) $rate;

                return $normalized > 0 ? $normalized : null;
            };
    }

    /**
     * @param list<int>|null $documentItemIds
     * @return array<string,mixed>
     */
    public function resolve(
        Invoice $invoice,
        ?array $documentItemIds = null,
        ?float $amountOverride = null,
        mixed $municipalPercentFallback = null,
    ): array {
        $invoiceAmount = $this->serviceAmount($invoice, $documentItemIds, $amountOverride);
        $federalMode = strtolower((string) $this->setting('nfse.tributacao_federal_mode', 'per_invoice_amounts'));
        $snapshot = $this->snapshot($invoice, $invoiceAmount, $documentItemIds);
        $situacao = $this->select($this->setting('nfse.federal_piscofins_situacao_tributaria', ''));
        $retentionType = $this->select($this->setting('nfse.federal_piscofins_tipo_retencao', ''));
        $csll = $this->retentionValue($invoiceAmount, 'nfse.federal_valor_csll');

        if ($csll === '' && $snapshot['csll_value'] !== '') {
            $csll = $snapshot['csll_value'];
        }

        $csllRetained = in_array($retentionType, ['3', '7', '8', '9'], true);

        if ($retentionType !== '' && !$csllRetained) {
            $csll = '';
        }

        $simples = in_array($this->simplesNacional(), [2, 3], true);
        $federalPercent = $this->decimal($this->setting(
            $simples ? 'nfse.tributos_fed_sn' : 'nfse.tributos_fed_p',
            '',
        ));
        $statePercent = $this->decimal($this->setting(
            $simples ? 'nfse.tributos_est_sn' : 'nfse.tributos_est_p',
            '',
        ));
        $municipalPercent = $this->decimal($this->setting(
            $simples ? 'nfse.tributos_mun_sn' : 'nfse.tributos_mun_p',
            '',
        ));

        if (!$simples && $municipalPercent === '') {
            $municipalPercent = $this->decimal($municipalPercentFallback);
        }

        if ($federalPercent === '' && $snapshot['federal_percent'] !== '') {
            $federalPercent = $snapshot['federal_percent'];
        }

        $taxIndicator = (
            $federalPercent !== ''
            || $statePercent !== ''
            || $municipalPercent !== ''
        ) ? 2 : 0;

        if ($taxIndicator === 2) {
            $federalPercent = $federalPercent !== '' ? $federalPercent : '0.00';
            $statePercent = $statePercent !== '' ? $statePercent : '0.00';
            $municipalPercent = $municipalPercent !== '' ? $municipalPercent : '0.00';
        }

        $irrf = $this->retentionValue($invoiceAmount, 'nfse.federal_valor_irrf');

        if ($irrf === '' && $snapshot['irrf_value'] !== '') {
            $irrf = $snapshot['irrf_value'];
        }

        if ($situacao === '' || $situacao === '0') {
            return $this->finalize([
                'federalPiscofinsSituacaoTributaria' => '',
                'federalPiscofinsTipoRetencao' => '',
                'federalPiscofinsBaseCalculo' => '',
                'federalPiscofinsAliquotaPis' => '',
                'federalPiscofinsValorPis' => '',
                'federalPiscofinsAliquotaCofins' => '',
                'federalPiscofinsValorCofins' => '',
                'federalValorIrrf' => $irrf,
                'federalValorCsll' => $csll,
                'federalValorCp' => '',
                'indicadorTributacao' => $taxIndicator,
                'totalTributosPercentualFederal' => $federalPercent,
                'totalTributosPercentualEstadual' => $statePercent,
                'totalTributosPercentualMunicipal' => $municipalPercent,
            ]);
        }

        $pisRate = $this->decimal($this->setting('nfse.federal_piscofins_aliquota_pis', ''));
        $cofinsRate = $this->decimal($this->setting('nfse.federal_piscofins_aliquota_cofins', ''));

        if (($federalMode === 'per_invoice_amounts' || $pisRate === '') && $snapshot['pis_rate'] !== '') {
            $pisRate = $snapshot['pis_rate'];
        }

        if (($federalMode === 'per_invoice_amounts' || $cofinsRate === '') && $snapshot['cofins_rate'] !== '') {
            $cofinsRate = $snapshot['cofins_rate'];
        }

        $pisValue = $pisRate !== ''
            ? number_format($invoiceAmount * (float) $pisRate / 100, 2, '.', '')
            : '';

        if (($federalMode === 'per_invoice_amounts' || $pisValue === '') && $snapshot['pis_value'] !== '') {
            $pisValue = $snapshot['pis_value'];
        }

        $cofinsValue = $cofinsRate !== ''
            ? number_format($invoiceAmount * (float) $cofinsRate / 100, 2, '.', '')
            : '';

        if (($federalMode === 'per_invoice_amounts' || $cofinsValue === '') && $snapshot['cofins_value'] !== '') {
            $cofinsValue = $snapshot['cofins_value'];
        }

        return $this->finalize([
            'federalPiscofinsSituacaoTributaria' => $situacao,
            'federalPiscofinsTipoRetencao' => $retentionType,
            'federalPiscofinsBaseCalculo' => number_format($invoiceAmount, 2, '.', ''),
            'federalPiscofinsAliquotaPis' => $pisRate,
            'federalPiscofinsValorPis' => $pisValue,
            'federalPiscofinsAliquotaCofins' => $cofinsRate,
            'federalPiscofinsValorCofins' => $cofinsValue,
            'federalValorIrrf' => $irrf,
            'federalValorCsll' => $csll,
            'federalValorCp' => '',
            'indicadorTributacao' => $taxIndicator,
            'totalTributosPercentualFederal' => $federalPercent,
            'totalTributosPercentualEstadual' => $statePercent,
            'totalTributosPercentualMunicipal' => $municipalPercent,
        ]);
    }

    /**
     * Resolve the gross service amount used by the DPS and as the PIS/COFINS
     * calculation base. Akaunting's document amount can already be reduced by
     * withholding taxes, so it is not a safe fiscal base.
     *
     * @param list<int>|null $documentItemIds
     */
    public function serviceAmount(
        Invoice $invoice,
        ?array $documentItemIds = null,
        ?float $amountOverride = null,
    ): float {
        if ($amountOverride !== null) {
            return max(0.0, $amountOverride);
        }

        $amount = 0.0;
        $hasItemTotal = false;

        foreach ($this->invoiceItems($invoice) as $item) {
            $documentItemId = is_numeric($item['id'] ?? null) ? (int) $item['id'] : 0;

            if ($documentItemIds !== null && !in_array($documentItemId, $documentItemIds, true)) {
                continue;
            }

            $total = $item['total'] ?? null;

            if (!is_numeric($total)) {
                continue;
            }

            $amount += (float) $total;
            $hasItemTotal = true;
        }

        if ($hasItemTotal && $amount > 0) {
            return $amount;
        }

        return max(0.0, (float) ($invoice->amount ?? 0.0));
    }

    /**
     * @param list<int>|null $documentItemIds
     * @return array{pis_value:string,pis_rate:string,cofins_value:string,cofins_rate:string,irrf_value:string,csll_value:string,federal_percent:string}
     */
    public function snapshot(
        Invoice $invoice,
        float $invoiceAmount,
        ?array $documentItemIds = null,
    ): array {
        $rateCache = [];

        return $this->snapshotBuilder->build(
            items: $this->invoiceItems($invoice),
            baseAmount: $invoiceAmount,
            documentItemIds: $documentItemIds,
            taxRateResolver: function (mixed $tax) use (&$rateCache): ?float {
                $inline = is_array($tax) ? ($tax['rate'] ?? null) : ($tax->rate ?? null);

                if (is_numeric($inline)) {
                    $rate = (float) $inline;

                    return $rate > 0 ? $rate : null;
                }

                $taxIdRaw = is_array($tax) ? ($tax['tax_id'] ?? null) : ($tax->tax_id ?? null);

                if (!is_numeric($taxIdRaw) || (int) $taxIdRaw <= 0) {
                    return null;
                }

                $taxId = (int) $taxIdRaw;

                if (!array_key_exists($taxId, $rateCache)) {
                    $rateCache[$taxId] = ($this->taxRateResolver)($taxId);
                }

                return $rateCache[$taxId];
            },
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function invoiceItems(Invoice $invoice): array
    {
        $items = $invoice->items;

        if (is_object($items) && method_exists($items, 'toArray')) {
            $rows = $items->toArray();

            return is_array($rows) ? array_values($rows) : [];
        }

        return is_array($items) ? array_values($items) : [];
    }

    private function simplesNacional(): int
    {
        $value = (int) $this->setting('nfse.opcao_simples_nacional', 1);

        return in_array($value, [1, 2, 3], true) ? $value : 1;
    }

    private function retentionValue(float $amount, string $key): string
    {
        $percentage = $this->decimal($this->setting($key, ''));

        if ($percentage === '' || (float) $percentage <= 0) {
            return '';
        }

        return number_format($amount * (float) $percentage / 100, 2, '.', '');
    }

    private function select(mixed $value): string
    {
        $normalized = trim((string) $value);

        return preg_match('/^\d+$/', $normalized) === 1 ? $normalized : '';
    }

    private function decimal(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        if ($normalized === '' || !is_numeric($normalized)) {
            return '';
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $hasTotals = $payload['totalTributosPercentualFederal'] !== ''
            || $payload['totalTributosPercentualEstadual'] !== ''
            || $payload['totalTributosPercentualMunicipal'] !== '';

        if ($hasTotals || !$this->hasTaxationPayload($payload)) {
            return $payload;
        }

        $payload['indicadorTributacao'] = 2;
        $payload['totalTributosPercentualFederal'] = '0.00';
        $payload['totalTributosPercentualEstadual'] = '0.00';
        $payload['totalTributosPercentualMunicipal'] = '0.00';

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hasTaxationPayload(array $payload): bool
    {
        return $payload['federalPiscofinsSituacaoTributaria'] !== ''
            || $payload['federalPiscofinsTipoRetencao'] !== ''
            || $payload['federalPiscofinsBaseCalculo'] !== ''
            || $payload['federalPiscofinsAliquotaPis'] !== ''
            || $payload['federalPiscofinsValorPis'] !== ''
            || $payload['federalPiscofinsAliquotaCofins'] !== ''
            || $payload['federalPiscofinsValorCofins'] !== ''
            || $this->nonZero($payload['federalValorIrrf'])
            || $this->nonZero($payload['federalValorCsll'])
            || $this->nonZero($payload['federalValorCp']);
    }

    private function nonZero(string $value): bool
    {
        return $value !== '' && (float) $value !== 0.0;
    }

    private function setting(string $key, mixed $default): mixed
    {
        return ($this->settingResolver)($key, $default);
    }
}
