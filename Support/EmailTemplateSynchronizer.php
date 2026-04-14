<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Modules\Nfse\Notifications\NfseIssued;

class EmailTemplateSynchronizer
{
    public const CANONICAL_ALIAS = 'invoice_nfse_issued_customer';
    public const LEGACY_ALIAS = 'nfse_issued_customer';
    public const NAME_KEY = 'settings.email.templates.invoice_nfse_issued_customer';

    public function sync(): void
    {
        $this->registerTranslations();

        if (!Schema::hasTable('email_templates')) {
            return;
        }

        $rows = DB::table('email_templates')
            ->where('class', NfseIssued::class)
            ->whereIn('alias', [self::LEGACY_ALIAS, self::CANONICAL_ALIAS])
            ->orderBy('id')
            ->get(['id', 'company_id', 'alias', 'name'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'company_id' => (int) $row->company_id,
                'alias' => (string) $row->alias,
                'name' => (string) $row->name,
            ])
            ->all();

        foreach ($this->groupByCompany($rows) as $companyRows) {
            $plan = self::planCompanyTemplates($companyRows);

            DB::table('email_templates')
                ->where('id', $plan['canonical_id'])
                ->update($plan['updates']);

            if ($plan['delete_ids'] !== []) {
                DB::table('email_templates')
                    ->whereIn('id', $plan['delete_ids'])
                    ->delete();
            }
        }
    }

    /**
     * @param array<int, array{id:int,company_id:int,alias:string,name:string}> $rows
     * @return array<int, array<int, array{id:int,company_id:int,alias:string,name:string}>>
     */
    private function groupByCompany(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row['company_id']][] = $row;
        }

        return $grouped;
    }

    public function registerTranslations(): void
    {
        foreach (self::buildTranslations() as $locale => $lines) {
            Lang::addLines($lines, $locale);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function buildTranslations(): array
    {
        return [
            'pt-BR' => [
                self::NAME_KEY => 'Modelo de NFS-e Emitida (enviado ao cliente)',
            ],
            'en-GB' => [
                self::NAME_KEY => 'Issued NFS-e Template (sent to customer)',
            ],
        ];
    }

    /**
     * @param array<int, array{id:int,company_id:int,alias:string,name:string}> $rows
     * @return array{canonical_id:int,updates:array{alias:string,name:string},delete_ids:list<int>}
     */
    public static function planCompanyTemplates(array $rows): array
    {
        usort($rows, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        $canonical = null;

        foreach ($rows as $row) {
            if ($row['alias'] === self::CANONICAL_ALIAS) {
                $canonical = $row;

                break;
            }
        }

        $canonical ??= $rows[0];

        $deleteIds = [];

        foreach ($rows as $row) {
            if ($row['id'] !== $canonical['id']) {
                $deleteIds[] = $row['id'];
            }
        }

        return [
            'canonical_id' => $canonical['id'],
            'updates' => [
                'alias' => self::CANONICAL_ALIAS,
                'name' => self::NAME_KEY,
            ],
            'delete_ids' => $deleteIds,
        ];
    }
}