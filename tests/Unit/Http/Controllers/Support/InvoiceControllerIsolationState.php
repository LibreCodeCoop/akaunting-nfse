<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/ControllerIsolationState.php';

    if (!class_exists(\App\Models\Document\Document::class, false)) {
        eval('namespace App\\Models\\Document; class Document { public function __construct(public int $id = 0, public float $amount = 0.0, public ?object $contact = null, public ?object $items = null, public string $description = "") { $this->items ??= new \\App\\Models\\Sale\\FakeCollection([]); } public static function invoice(): object { return new class () { public function when(bool $condition, callable $callback): self { if ($condition) { $callback($this); } return $this; } public function whereNotIn(string $column, array $values): self { return $this; } public function where(mixed ...$args): self { if (($args[0] ?? null) instanceof \\Closure) { $args[0]($this); } return $this; } public function orWhereHas(string $relation, callable $callback): self { $callback($this); return $this; } public function latest(): self { return $this; } public function paginate(int $perPage): array { return []; } }; } }');
    }

    if (!class_exists(\App\Models\Sale\Invoice::class, false)) {
        eval('namespace App\\Models\\Sale; class Invoice extends \\App\\Models\\Document\\Document {} class FakeCollection { public function __construct(private array $items) {} public function pluck(string $key): self { return new self(array_map(static fn (array|object $item): mixed => is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null), $this->items)); } public function toArray(): array { return $this->items; } }');
    }

    if (!class_exists(\Modules\Nfse\Models\NfseReceipt::class, false)) {
        eval('namespace Modules\\Nfse\\Models; class NfseReceipt { public static array $records = []; public static array $updateOrCreateCalls = []; public static array $paginateItems = []; public int $invoice_id = 0; public string $nfse_number = ""; public string $chave_acesso = ""; public string $data_emissao = ""; public ?string $codigo_verificacao = null; public string $status = ""; public ?string $danfse_webdav_path = null; public ?string $xml_webdav_path = null; public array $updatedPayloads = []; public static function with(string $relation): object { return new class () { public function latest(): object { return $this; } public function paginate(int $perPage): array { return \\Modules\\Nfse\\Models\\NfseReceipt::$paginateItems; } }; } public static function where(string $field, mixed $value): object { return new class ($field, $value) { public function __construct(private string $field, private mixed $value) {} public function firstOrFail(): \\Modules\\Nfse\\Models\\NfseReceipt { foreach (\\Modules\\Nfse\\Models\\NfseReceipt::$records as $record) { if (($record->{$this->field} ?? null) === $this->value) { return $record; } } throw new \\RuntimeException("Receipt not found."); } }; } public static function updateOrCreate(array $attributes, array $values): self { self::$updateOrCreateCalls[] = ["attributes" => $attributes, "values" => $values]; $record = new self(); foreach (array_merge($attributes, $values) as $key => $value) { $record->{$key} = $value; } self::$records[] = $record; return $record; } public function update(array $values): void { $this->updatedPayloads[] = $values; foreach ($values as $key => $value) { $this->{$key} = $value; } } }');
    }
}

namespace {
    if (!class_exists(\App\Models\Setting\EmailTemplate::class, false)) {
        eval('namespace App\\Models\\Setting; class EmailTemplate { public static ?self $stubInstance = null; public static int $savedCount = 0; public string $subject = ""; public string $body = ""; public function save(): void { self::$savedCount++; } public static function alias(string $alias): object { return new class (self::$stubInstance) { public function __construct(private ?\\App\\Models\\Setting\\EmailTemplate $t) {} public function first(): ?\\App\\Models\\Setting\\EmailTemplate { return $this->t; } }; } }');
    }
}

namespace Modules\Nfse\Tests\Unit\Http\Controllers\Support {
    use App\Models\Sale\FakeCollection;
    use App\Models\Sale\Invoice;
    use App\Models\Setting\EmailTemplate;
    use Modules\Nfse\Http\Controllers\ControllerIsolationState;
    use Modules\Nfse\Models\NfseReceipt;

    final class InvoiceControllerIsolationState
    {
        public static function reset(): void
        {
            ControllerIsolationState::reset();
            NfseReceipt::$records = [];
            NfseReceipt::$updateOrCreateCalls = [];
            NfseReceipt::$paginateItems = [];
            EmailTemplate::$stubInstance = null;
        }

        /**
         * @param list<array{name: string}> $items
         */
        public static function makeInvoice(
            int $id,
            float $amount,
            array $items = [],
            string $description = '',
            ?string $contactName = null,
            ?string $contactTaxNumber = null,
            ?string $contactAddress = null,
            ?string $contactZipCode = null,
            ?string $contactCityIbge = null,
            ?string $contactPhone = null,
            ?string $contactEmail = null,
        ): Invoice {
            $contact = null;

            if ($contactName !== null || $contactTaxNumber !== null || $contactAddress !== null || $contactZipCode !== null || $contactCityIbge !== null || $contactPhone !== null || $contactEmail !== null) {
                $contact = (object) [
                    'name' => $contactName,
                    'tax_number' => $contactTaxNumber,
                    'address' => $contactAddress,
                    'zip_code' => $contactZipCode,
                    'municipio_ibge' => $contactCityIbge,
                    'phone' => $contactPhone,
                    'email' => $contactEmail,
                ];
            }

            return new Invoice(
                id: $id,
                amount: $amount,
                contact: $contact,
                items: new FakeCollection($items),
                description: $description,
            );
        }

        public static function makeReceipt(int $invoiceId, string $chaveAcesso, string $status = 'emitted'): NfseReceipt
        {
            $receipt = new NfseReceipt();
            $receipt->invoice_id = $invoiceId;
            $receipt->chave_acesso = $chaveAcesso;
            $receipt->status = $status;

            NfseReceipt::$records[] = $receipt;

            return $receipt;
        }
    }
}
