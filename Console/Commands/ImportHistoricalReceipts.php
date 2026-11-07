<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Console\Commands;

use App\Models\Common\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\HistoricalReceiptManifest;
use Modules\Nfse\Models\NfseReceipt;
use Modules\Nfse\Support\NfseCompanyFiscalSettings;
use Modules\Nfse\Support\WebDavClient;

/**
 * Idempotent fiscal *archive* import. It does not create customers,
 * accounting invoices, banking transactions, or fiscal authorizations.
 */
final class ImportHistoricalReceipts extends Command
{
    protected $signature = 'nfse:archive-import
        {company : Akaunting company ID}
        {manifest : Private JSON manifest from the historical fiscal audit}
        {--contact-map= : Optional reviewed recipient-tax to customer-contact map}
        {--apply : Execute the validated fiscal archive import (otherwise dry run)}';

    protected $description = 'Validate and import archival NFS-e without issuing or altering financial records.';

    public function handle(): int
    {
        $companyId = (int) $this->argument('company');
        $manifestPath = (string) $this->argument('manifest');
        if ($companyId <= 0 || !is_file($manifestPath) || !is_readable($manifestPath)) {
            $this->error('A valid company and readable private manifest are required.');

            return self::FAILURE;
        }

        $previousCompany = Company::getCurrent();

        try {
            company($companyId)->makeCurrent();

            $config = (new NfseCompanyFiscalSettings())->all($companyId);
            $issuer = HistoricalReceiptManifest::digits($config['cnpj_prestador'] ?? '');
            if (!HistoricalReceiptManifest::validTaxId($issuer)) {
                throw new \DomainException('The configured company fiscal identity is not valid.');
            }

            $raw = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
                throw new \DomainException('The historical manifest must be a nonempty JSON array.');
            }

            $rows = [];
            foreach ($raw as $index => $item) {
                if (!is_array($item)) {
                    throw new \DomainException('Invalid historical record at index ' . $index);
                }
                $normalized = HistoricalReceiptManifest::normalize($item, $companyId, $issuer);
                $key = $normalized['archive_identity'];
                if (isset($rows[$key])) {
                    throw new \DomainException('Duplicate fiscal identity within the historical manifest.');
                }
                $rows[$key] = $normalized;
            }

            $contactMap = $this->readContactMap((string) ($this->option('contact-map') ?: ''), $rows);
            $contacts = $this->resolveCustomers($companyId, $rows, $contactMap);
            foreach ($rows as $key => &$row) {
                $tax = (string) $row['archive_evidence']['recipient_tax'];
                $row['recipient_contact_id'] = $contacts[$tax] ?? null;
            }
            unset($row);

            // Verify original consolidated files in Nextcloud. Several fiscal
            // notes can share a PDF; download each unique path only once.
            $settings = DB::table('settings')->where('company_id', $companyId)
                ->where('key', 'like', 'nfse.webdav%')->pluck('value', 'key');
            $client = new WebDavClient(
                (string) ($settings['nfse.webdav_url'] ?? ''),
                (string) ($settings['nfse.webdav_username'] ?? ''),
                (string) ($settings['nfse.webdav_password'] ?? ''),
            );

            $files = [];
            foreach ($rows as $row) {
                $path = (string) $row['danfse_webdav_path'];
                $expected = (string) $row['archive_pdf_sha256'];
                if (isset($files[$path])) {
                    if ($files[$path] !== $expected) {
                        throw new \DomainException('A consolidated PDF has conflicting hashes.');
                    }
                    continue;
                }

                $pdf = $client->get($path);
                if (!str_starts_with($pdf, '%PDF-') || !hash_equals($expected, hash('sha256', $pdf))) {
                    throw new \DomainException('An archived Nextcloud PDF differs from its audited SHA-256.');
                }
                $files[$path] = $expected;
            }

            $result = $this->reconcileArchive($companyId, $rows, $issuer, (bool) $this->option('apply'));
            $this->line(sprintf(
                '%s: validated=%d, original_pdfs=%d, would_insert=%d, existing=%d, would_link_customer=%d.',
                $this->option('apply') ? 'Applied' : 'Dry run',
                count($rows), count($files), $result['insert'], $result['existing'], $result['link'],
            ));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('Historical archive was not imported: ' . $error->getMessage());

            return self::FAILURE;
        } finally {
            if ($previousCompany instanceof Company) {
                $previousCompany->makeCurrent();
            } else {
                Company::forgetCurrent();
            }
        }
    }

    /**
     * Reviewed map can only reuse *existing active customer* IDs.
     * The independent evidence reference is mandatory when the customer record
     * lacks a CPF/CNPJ; a name resemblance alone is never enough.
     *
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function readContactMap(string $path, array $rows): array
    {
        if ($path === '') {
            return [];
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \DomainException('The reviewed contact map does not exist.');
        }
        $map = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($map) || array_is_list($map)) {
            throw new \DomainException('Expected an object mapping recipient tax IDs to reviewed customers.');
        }

        $knownTaxes = array_fill_keys(array_map(
            static fn (array $r): string => (string) $r['archive_evidence']['recipient_tax'],
            array_values($rows),
        ), true);
        $output = [];
        $usedIds = [];
        foreach ($map as $taxRaw => $entry) {
            $tax = HistoricalReceiptManifest::digits($taxRaw);
            if (!isset($knownTaxes[$tax]) || !is_array($entry)
                || (int) ($entry['contact_id'] ?? 0) <= 0
                || trim((string) ($entry['evidence_reference'] ?? '')) === '') {
                throw new \DomainException('A contact map entry lacks a reviewed source or tax identity.');
            }
            $id = (int) $entry['contact_id'];
            if (isset($usedIds[$id]) && $usedIds[$id] !== $tax) {
                throw new \DomainException('One contact cannot be mapped to two different taxpayer IDs.');
            }
            if (isset($output[$tax]) && $output[$tax] !== $id) {
                throw new \DomainException('Conflicting contact map entries.');
            }
            $usedIds[$id] = $tax;
            $output[$tax] = $id;
        }

        return $output;
    }

    /**
     * Prefer exact verified CPF/CNPJ. An ambiguous active match blocks import,
     * and any user-reviewed name alias cannot override an existing tax ID.
     *
     * @param array<string, array<string, mixed>> $rows
     * @param array<string, int> $contactMap
     * @return array<string, int>
     */
    private function resolveCustomers(int $companyId, array $rows, array $contactMap): array
    {
        $customers = DB::table('contacts')->where('company_id', $companyId)
            ->where('type', 'customer')->whereNull('deleted_at')
            ->get(['id', 'tax_number']);
        $byTax = [];
        $byId = [];
        foreach ($customers as $contact) {
            $byId[(int) $contact->id] = $contact;
            $tax = HistoricalReceiptManifest::digits($contact->tax_number);
            if ($tax !== '') {
                $byTax[$tax][] = (int) $contact->id;
            }
        }

        $matched = [];
        foreach ($rows as $row) {
            $tax = (string) $row['archive_evidence']['recipient_tax'];
            if (isset($matched[$tax])) {
                continue;
            }
            $exact = $byTax[$tax] ?? [];
            if (count($exact) > 1) {
                throw new \DomainException('Ambiguous active customers share a historical taxpayer ID.');
            }
            $reviewed = $contactMap[$tax] ?? null;
            if ($reviewed !== null && !isset($byId[$reviewed])) {
                throw new \DomainException('A reviewed customer does not belong to the active company.');
            }
            if ($exact !== [] && $reviewed !== null && $reviewed !== $exact[0]) {
                throw new \DomainException('Reviewed contact would override an exact taxpayer match.');
            }
            if ($reviewed !== null) {
                $liveTax = HistoricalReceiptManifest::digits($byId[$reviewed]->tax_number);
                if ($liveTax !== '' && $liveTax !== $tax) {
                    throw new \DomainException('Reviewed customer taxpayer ID conflicts with the archive.');
                }
            }
            if ($exact !== [] || $reviewed !== null) {
                $matched[$tax] = $exact[0] ?? $reviewed;
            }
        }

        return $matched;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return array{insert:int, existing:int, link:int}
     */
    private function reconcileArchive(int $companyId, array $rows, string $issuer, bool $apply): array
    {
        return DB::transaction(function () use ($companyId, $rows, $issuer, $apply): array {
            if ($apply && !DB::table('companies')->where('id', $companyId)->lockForUpdate()->exists()) {
                throw new \DomainException('The company was removed before import.');
            }

            $insert = 0;
            $existing = 0;
            $link = 0;
            foreach ($rows as $key => $row) {
                $current = NfseReceipt::query()->where('archive_identity', $key)->first();
                if ($current) {
                    if ((int) $current->company_id !== $companyId
                        || (string) $current->nfse_number !== (string) $row['nfse_number']
                        || (string) $current->data_emissao?->format('Y-m-d H:i:s') !== (string) $row['data_emissao']
                        || (string) $current->status !== (string) $row['status']
                        || (string) $current->archive_pdf_sha256 !== (string) $row['archive_pdf_sha256']
                        || (int) $current->archive_page_number !== (int) $row['archive_page_number']
                        || ltrim((string) $current->danfse_webdav_path, '/') !== $row['danfse_webdav_path']
                        || HistoricalReceiptManifest::digits($current->archive_evidence['recipient_tax'] ?? '') !== $row['archive_evidence']['recipient_tax']) {
                        throw new \DomainException('Fiscal archive identity collides with a changed historical document.');
                    }
                    ++$existing;
                    if ($row['recipient_contact_id'] !== null) {
                        if ($current->recipient_contact_id !== null
                            && (int) $current->recipient_contact_id !== (int) $row['recipient_contact_id']) {
                            throw new \DomainException('Previously linked recipient cannot be silently reassigned.');
                        }
                        if ($current->recipient_contact_id === null) {
                            ++$link;
                            if ($apply) {
                                $current->recipient_contact_id = $row['recipient_contact_id'];
                                $current->save();
                            }
                        }
                    }
                    continue;
                }

                // Do not duplicate a note that was previously imported under
                // an accounting invoice before archive identities existed.
                $collisions = DB::table('nfse_receipts as nr')
                    ->leftJoin('documents as doc', 'doc.id', '=', 'nr.invoice_id')
                    ->where('nr.nfse_number', $row['nfse_number'])
                    ->whereYear('nr.data_emissao', substr((string) $row['data_emissao'], 0, 4))
                    ->where(static fn ($query) => $query
                        ->where('nr.company_id', $companyId)
                        ->orWhere('doc.company_id', $companyId))
                    ->exists();
                if ($collisions) {
                    throw new \DomainException('Historical fiscal number already exists without this archive identity.');
                }

                ++$insert;
                if ($row['recipient_contact_id'] !== null) {
                    ++$link;
                }
                if ($apply) {
                    NfseReceipt::query()->create($row);
                }
            }

            // Links are established only after every document exists.
            // Missing/ambiguous predecessors remain documentary evidence,
            // not invented relations.
            if ($apply) {
                foreach ($rows as $row) {
                    $previousNumber = (string) ($row['archive_evidence']['replaces_fiscal_number'] ?? '');
                    if ($previousNumber === '') {
                        continue;
                    }
                    $year = substr((string) $row['data_emissao'], 0, 4);
                    $predecessorKey = hash('sha256', implode("\0", [
                        'historical-nfse-v1', (string) $companyId, $issuer, $year,
                        ltrim($previousNumber, '0') ?: '0',
                    ]));
                    $previous = NfseReceipt::query()->where('archive_identity', $predecessorKey)->first();
                    $current = NfseReceipt::query()->where('archive_identity', $row['archive_identity'])->first();
                    if ($previous && $current && $current->id !== $previous->id
                        && $current->replaces_receipt_id === null
                        && (int) ($previous->replaces_receipt_id ?? 0) !== (int) $current->id) {
                        $current->replaces_receipt_id = $previous->id;
                        $current->save();
                    }
                }
            }

            return compact('insert', 'existing', 'link');
        });
    }
}
