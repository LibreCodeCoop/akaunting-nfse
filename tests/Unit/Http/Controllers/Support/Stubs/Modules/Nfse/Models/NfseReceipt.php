<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Models {
    if (!class_exists(\Modules\Nfse\Models\NfseReceipt::class, false)) {
        class NfseReceipt
        {
            public static array $records = [];
            public static array $updateOrCreateCalls = [];
            public static array $paginateItems = [];
            public static array $payloads = [];
            public int $id = 0;
            public int $invoice_id = 0;
            public string $nfse_number = "";
            public string $chave_acesso = "";
            public string $data_emissao = "";
            public ?string $codigo_verificacao = null;
            public string $status = "";
            public ?string $danfse_webdav_path = null;
            public ?string $xml_webdav_path = null;
            public array $updatedPayloads = [];
            public static function with(string $relation): object
            {
                return new class () {
                    public function latest(): object
                    {
                        return $this;
                    } public function paginate(int $perPage): array
                    {
                        return \Modules\Nfse\Models\NfseReceipt::$paginateItems;
                    }
                };
            } public static function where(string $field, mixed $value): object
            {
                return new class ($field, $value) {
                    public function __construct(private string $field, private mixed $value)
                    {
                    } public function latest(string $column = "id"): self
                    {
                        return $this;
                    } public function firstOrFail(): \Modules\Nfse\Models\NfseReceipt
                    {
                        foreach (\Modules\Nfse\Models\NfseReceipt::$records as $record) {
                            if (($record->{$this->field} ?? null) === $this->value) {
                                return $record;
                            }
                        } throw new \RuntimeException("Receipt not found.");
                    }
                };
            } public static function updateOrCreate(array $attributes, array $values): self
            {
                self::$updateOrCreateCalls[] = ["attributes" => $attributes, "values" => $values];
                $record = new self();
                $record->id = count(self::$records) + 1;
                foreach (array_merge($attributes, $values) as $key => $value) {
                    $record->{$key} = $value;
                } self::$records[] = $record;
                return $record;
            } public function update(array $values): void
            {
                $this->updatedPayloads[] = $values;
                foreach ($values as $key => $value) {
                    $this->{$key} = $value;
                }
            } public function fresh(): self
            {
                return $this;
            } public function payload(): object
            {
                return new class ($this->id) {
                    public function __construct(private int $receiptId)
                    {
                    } public function updateOrCreate(array $attributes, array $values): object
                    {
                        \Modules\Nfse\Models\NfseReceipt::$payloads[$this->receiptId] = array_merge(\Modules\Nfse\Models\NfseReceipt::$payloads[$this->receiptId] ?? [], $values);
                        return (object) \Modules\Nfse\Models\NfseReceipt::$payloads[$this->receiptId];
                    } public function value(string $key): mixed
                    {
                        return \Modules\Nfse\Models\NfseReceipt::$payloads[$this->receiptId][$key] ?? null;
                    } public function getResults(): ?object
                    {
                        $values = \Modules\Nfse\Models\NfseReceipt::$payloads[$this->receiptId] ?? null;
                        return is_array($values) ? (object) $values : null;
                    } public function firstOrCreate(): object
                    {
                        $values = \Modules\Nfse\Models\NfseReceipt::$payloads[$this->receiptId] ?? ["post_emission_email_sent_at" => null];
                        return new class ($this->receiptId, $values) {
                            public function __construct(private int $receiptId, public ?string $post_emission_email_sent_at = null)
                            {
                            } public function update(array $values): void
                            {
                                foreach ($values as $key => $value) {
                                    $this->{$key} = $value;
                                } \Modules\Nfse\Models\NfseReceipt::$payloads[$this->receiptId] = array_merge(\Modules\Nfse\Models\NfseReceipt::$payloads[$this->receiptId] ?? [], $values);
                            }
                        };
                    }
                };
            }
        }
    }
}
