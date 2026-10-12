<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\DB;

/**
 * Selects a source revision by actual fiscal context, without estimating taxes.
 * No match means unknown, never a numeric zero or inferred ISSQN percentage.
 */
final class ApproximateTaxSourceResolver
{
    /** @var \Closure(int,string,int,string):?array */
    private readonly \Closure $lookup;

    /** @param (\Closure(int,string,int,string):?array)|null $lookup */
    public function __construct(?\Closure $lookup = null)
    {
        $this->lookup = $lookup ?? static function (
            int $companyId,
            string $nationalCode,
            int $simplesOption,
            string $competence,
        ): ?array {
            // Isolated unit-controller tests do not bootstrap Laravel. Actual
            // Akaunting Feature tests exercise the database query unchanged.
            if (!class_exists(DB::class)) {
                return null;
            }

            $row = DB::table('nfse_approximate_tax_sources')
                ->where('company_id', $companyId)
                ->where('national_service_code', $nationalCode)
                ->where('simples_option', $simplesOption)
                ->where('competence_from', '<=', $competence)
                ->where(static function ($query) use ($competence): void {
                    $query->whereNull('competence_until')
                        ->orWhere('competence_until', '>=', $competence);
                })
                ->orderByDesc('competence_from')
                ->orderByDesc('id')
                ->first();

            return $row === null ? null : (array) $row;
        };
    }

    /**
     * @return array{status:string,reason:?string,source:?array<string,mixed>,federal:string,state:string,municipal:string}
     */
    public function resolve(int $companyId, string $nationalCode, int $simplesOption, string $competence): array
    {
        if ($companyId <= 0 || preg_match('/^\d{6}$/D', $nationalCode) !== 1) {
            return $this->unknown('missing_company_or_service');
        }

        if (!in_array($simplesOption, [1, 2, 3], true) || !$this->dateValid($competence)) {
            throw new \InvalidArgumentException('Approximate tax context requires a valid regime and DPS competence.');
        }

        $row = ($this->lookup)($companyId, $nationalCode, $simplesOption, $competence);

        if ($row === null) {
            return $this->unknown('source_not_configured');
        }

        if ((int) ($row['company_id'] ?? 0) !== $companyId
            || (string) ($row['national_service_code'] ?? '') !== $nationalCode
            || (int) ($row['simples_option'] ?? 0) !== $simplesOption
            || !$this->covers($row, $competence)) {
            throw new \LogicException('Approximate tax source does not match the fiscal operation.');
        }

        $kind = (string) ($row['source_kind'] ?? '');
        if (!in_array($kind, ['independent_estimate', 'taxpayer_declaration', 'documented_zero'], true)
            || trim((string) ($row['source_name'] ?? '')) === ''
            || trim((string) ($row['source_version'] ?? '')) === ''
            || trim((string) ($row['source_reference'] ?? '')) === '') {
            throw new \LogicException('Approximate tax source is incomplete or unsupported.');
        }

        $federal = $this->percent($row['federal_percent'] ?? null);
        $state = $this->percent($row['state_percent'] ?? null);
        $municipal = $this->percent($row['municipal_percent'] ?? null);

        if ($kind === 'documented_zero' && ($federal !== '0.00' || $state !== '0.00' || $municipal !== '0.00')) {
            throw new \LogicException('Documented zero source must contain three confirmed zero percentages.');
        }

        return [
            'status' => match ($kind) {
                'documented_zero' => 'documented_zero',
                'independent_estimate' => 'sourced_estimate',
                default => 'taxpayer_declaration',
            },
            'reason' => null,
            'source' => [
                'id' => (int) ($row['id'] ?? 0),
                'kind' => $kind,
                'name' => (string) $row['source_name'],
                'version' => (string) $row['source_version'],
                'reference' => (string) $row['source_reference'],
                'company_id' => $companyId,
                'national_service_code' => $nationalCode,
                'simples_option' => $simplesOption,
                'competence' => $competence,
                'competence_from' => (string) $row['competence_from'],
                'competence_until' => ($row['competence_until'] ?? null) !== null
                    ? (string) $row['competence_until']
                    : null,
            ],
            'federal' => $federal,
            'state' => $state,
            'municipal' => $municipal,
        ];
    }

    /** @return array{status:string,reason:string,source:null,federal:string,state:string,municipal:string} */
    private function unknown(string $reason): array
    {
        return [
            'status' => 'unknown',
            'reason' => $reason,
            'source' => null,
            'federal' => '',
            'state' => '',
            'municipal' => '',
        ];
    }

    /** @param array<string,mixed> $row */
    private function covers(array $row, string $competence): bool
    {
        $from = (string) ($row['competence_from'] ?? '');
        $until = $row['competence_until'] ?? null;

        return $this->dateValid($from) && $from <= $competence
            && ($until === null || ($this->dateValid((string) $until) && (string) $until >= $competence));
    }

    private function dateValid(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function percent(mixed $value): string
    {
        $raw = trim((string) $value);
        if (preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/D', $raw) !== 1) {
            throw new \LogicException('Approximate tax percent is not a valid two-decimal percentage.');
        }

        $parts = explode('.', $raw, 2);

        return $parts[0] . '.' . str_pad($parts[1] ?? '', 2, '0');
    }
}
