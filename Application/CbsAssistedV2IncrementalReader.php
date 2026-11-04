<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Support\ExactTaxDecimal;

/**
 * Interprets one official-schema CBS v2 incremental JSON file without any
 * calculation or declaration of payable taxes. The archive supplied here is
 * UNAUTHENTICATED until a separate trusted downloader proves its provenance.
 *
 * The v2 API returns CHANGES, not an exhaustive competence summary.
 * This reader NEVER totals delta rows as an established tax balance.
 *
 * Source: https://docs.receitafederal.gov.br/apuracao-cbs/
 * debit/credit/payment/collection layouts v1.2 (14 Oct 2026).
 */
final class CbsAssistedV2IncrementalReader
{
    /**
     * @return array{
     *   kind:string,source_sha256:string,source_provenance:string,
     *   expected_cnpj_base:string,incremental:bool,complete_period:bool,
     *   events:list<array<string,mixed>>
     * }
     */
    public function read(
        string $json,
        string $kind,
        string $expectedCnpjBase,
        string $expectedTicket,
    ): array {
        if (strlen($json) > 5_000_000) {
            throw new \DomainException('CBS response exceeds bounded offline ingestion size.');
        }

        $expected = (new CbsAssistedV2Protocol())->begin($kind, $expectedCnpjBase);
        unset($expected); // validates the known type and eight-digit base
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $expectedTicket) !== 1) {
            throw new \InvalidArgumentException('Invalid consultation ticket.');
        }

        $document = $this->decodePreservingJsonNumbers($json, $kind);
        if (($document['ni'] ?? null) !== $expectedCnpjBase
            || ($document['tiqueteSolicitacao'] ?? null) !== $expectedTicket) {
            throw new \DomainException('CBS response does not match the requested taxpayer and ticket.');
        }
        if (!is_array($document['apuracao'] ?? null) || !array_is_list($document['apuracao'])) {
            throw new \DomainException('CBS response does not contain a valid period listing.');
        }

        $events = [];
        foreach ($document['apuracao'] as $group) {
            if (!is_array($group)) {
                throw new \DomainException('Invalid CBS assessment group.');
            }
            if (in_array($kind, ['debitos', 'creditos'], true)) {
                $events = array_merge($events, $this->documentEvents($group, $kind, $expectedCnpjBase));
            } else {
                $events = array_merge($events, $this->paymentEvents($group, $kind, $expectedCnpjBase));
            }
            if (count($events) > 10000) {
                throw new \DomainException('CBS response contains too many events for one ingestion.');
            }
        }

        return [
            'kind' => $kind,
            'source_sha256' => hash('sha256', $json),
            'source_provenance' => 'not_authenticated_by_parser',
            'expected_cnpj_base' => $expectedCnpjBase,
            'incremental' => true,
            'complete_period' => false,
            'events' => $events,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function documentEvents(array $group, string $kind, string $cnpj): array
    {
        $period = $this->period($group['pa'] ?? null);
        $entries = $group[$kind] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new \DomainException('Invalid CBS document updates array.');
        }
        $events = [];
        $balanceField = $kind === 'debitos' ? 'saldoDevedor' : 'saldoCredor';
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_array($entry['cbs'] ?? null)) {
                throw new \DomainException('Invalid CBS document update.');
            }
            $type = $this->positiveNumber($entry['documento'] ?? null);
            $origin = $this->nonNegativeNumber($entry['origem'] ?? null);
            $key = $entry['chave'] ?? null;
            if ($key !== null && (!is_string($key) || strlen($key) > 100)) {
                throw new \DomainException('Invalid CBS fiscal document key.');
            }
            $updated = $this->timestamp($entry['atualizacao'] ?? null);
            $applied = $this->money($entry['cbs']['apurado'] ?? null);
            $balance = $kind === 'debitos'
                ? $this->money($entry['cbs']['saldoDevedor'] ?? null)
                : $this->money($entry['cbs']['apropriacao']['utilizacao']['naoUtilizado']['saldoCredor'] ?? null);

            // The key is optional in official layouts. Missing keys cannot
            // safely be grouped or merged by matching amounts and timestamps.
            $identity = $key !== null && $key !== ''
                ? hash('sha256', implode('|', [$cnpj, $kind, $period, $type, $origin, $key]))
                : null;

            $events[] = [
                'period' => $period,
                'document_type' => $type,
                'origin' => $origin,
                'document_key' => $key,
                'updated_at' => $updated,
                'identity_key' => $identity,
                'identity_status' => $identity !== null ? 'keyed' : 'unverifiable',
                'official_reported_amount' => $applied,
                'official_reported_balance' => $balance,
                'balance_field' => $balanceField,
                'effect' => substr($period, 0, 4) === '2026' ? 'simulation_2026' : 'not_determined',
            ];
        }

        return $events;
    }

    /** @return list<array<string,mixed>> */
    private function paymentEvents(array $group, string $kind, string $cnpj): array
    {
        $collectedAt = $this->timestamp($group['dataArrecadacao'] ?? null);
        $payments = $group['pagamentos'] ?? null;
        if (!is_array($payments) || !array_is_list($payments)) {
            throw new \DomainException('Invalid CBS payment updates array.');
        }
        $events = [];
        foreach ($payments as $payment) {
            if (!is_array($payment) || !is_array($payment['composicao'] ?? null)) {
                throw new \DomainException('Invalid CBS payment composition.');
            }
            $darf = $payment['numeroDARF'] ?? null;
            if (!is_string($darf) || preg_match('/^[0-9]{17}$/D', $darf) !== 1) {
                throw new \DomainException('Invalid CBS DARF number.');
            }

            foreach ($payment['composicao'] as $item) {
                if (!is_array($item)) {
                    throw new \DomainException('Invalid CBS payment item.');
                }
                $period = $this->period($item['pa'] ?? null);
                $sequence = $this->positiveNumber($item['sequencial'] ?? null);
                $events[] = [
                    'period' => $period,
                    'darf' => $darf,
                    // The service's example and textual enumeration disagree
                    // about one collection type; do not infer its semantics.
                    'payment_type_observed' => $this->positiveNumber($payment['tipo'] ?? null),
                    'sequence' => $sequence,
                    'updated_at' => $collectedAt,
                    'document_key' => $item['chaveDFE'] ?? null,
                    'identity_key' => hash('sha256', implode('|', [$cnpj, $kind, $period, $darf, $sequence])),
                    'identity_status' => 'keyed',
                    'principal' => $this->money($item['principal'] ?? null),
                    'fine' => $this->money($item['multa'] ?? null),
                    'interest' => $this->money($item['juros'] ?? null),
                    'reported_total' => $this->money($item['total'] ?? null),
                    'effect' => substr($period, 0, 4) === '2026' ? 'simulation_2026' : 'not_determined',
                ];
            }
        }

        return $events;
    }

    /**
     * Decode official JSON decimal literals losslessly. PHP json_decode
     * otherwise turns e.g. 18-digit monetary numbers into floating point.
     * Quote numeric literals (never strings) before JSON decoding so every
     * amount can be validated with ExactTaxDecimal and integer cents.
     *
     * @return array<string,mixed>
     */
    private function decodePreservingJsonNumbers(string $raw, string $kind): array
    {
        $converted = preg_replace_callback(
            '/"(?:\\\\.|[^"\\\\])*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/',
            static function (array $match): string {
                return $match[0][0] === '"' ? $match[0] : '"' . $match[0] . '"';
            },
            $raw,
        );
        if (!is_string($converted)) {
            throw new \DomainException('CBS response could not be decoded safely.');
        }

        try {
            $source = json_decode($converted, false, 64, JSON_THROW_ON_ERROR);
            $decoded = json_decode($converted, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('CBS response is not valid JSON.');
        }
        // JSON object {} and array [] both become [] in associative decoding;
        // verify the exact wire-level shape before converting to arrays.
        if (!$source instanceof \stdClass || !is_array($source->apuracao ?? null)
            || !is_array($decoded) || array_is_list($decoded)) {
            throw new \DomainException('CBS response must contain a period array.');
        }
        foreach ($source->apuracao as $group) {
            if (!$group instanceof \stdClass) {
                throw new \DomainException('CBS period record must be an object.');
            }
            $records = in_array($kind, ['debitos', 'creditos'], true)
                ? ($group->$kind ?? null) : ($group->pagamentos ?? null);
            if (!is_array($records)) {
                throw new \DomainException('CBS record collection must be an array.');
            }
            foreach ($records as $record) {
                if (!$record instanceof \stdClass) {
                    throw new \DomainException('CBS record must be an object.');
                }
                $details = in_array($kind, ['debitos', 'creditos'], true)
                    ? ($record->cbs ?? null) : ($record->composicao ?? null);
                if (in_array($kind, ['debitos', 'creditos'], true) && !$details instanceof \stdClass) {
                    throw new \DomainException('CBS tax record must be an object.');
                }
                if (in_array($kind, ['pagamentos', 'recolhimentos'], true) && !is_array($details)) {
                    throw new \DomainException('CBS payment items must be an array.');
                }
            }
        }

        return $decoded;
    }

    private function period(mixed $raw): string
    {
        if (!is_string($raw) || preg_match('/^(0[1-9]|1[0-2])\/(20[2-9][0-9])$/D', $raw, $m) !== 1) {
            throw new \DomainException('CBS period must be MM/YYYY.');
        }

        return $m[2] . '-' . $m[1];
    }

    private function timestamp(mixed $raw): string
    {
        if (!is_string($raw)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $raw) !== 1) {
            throw new \DomainException('Invalid CBS event timestamp.');
        }

        return $raw;
    }

    private function nonNegativeNumber(mixed $raw): int
    {
        if (!is_string($raw) || preg_match('/^[0-9]{1,6}$/D', $raw) !== 1) {
            throw new \DomainException('Invalid CBS numeric enumeration.');
        }

        return (int) $raw;
    }

    private function positiveNumber(mixed $raw): int
    {
        $number = $this->nonNegativeNumber($raw);
        if ($number === 0) {
            throw new \DomainException('Invalid CBS document or payment ID.');
        }

        return $number;
    }

    private function money(mixed $raw): string
    {
        if (!is_string($raw) || preg_match('/^\d{1,16}(?:\.\d{1,2})?$/D', $raw) !== 1) {
            throw new \DomainException('Invalid CBS monetary amount or sub-cent precision.');
        }

        $cents = ExactTaxDecimal::cents($raw);
        if ($cents === null || $cents < 0) {
            throw new \DomainException('Invalid CBS reported monetary amount.');
        }

        return ExactTaxDecimal::formatCents($cents);
    }
}
