<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models\Document {
    if (!class_exists(\App\Models\Document\Document::class, false)) {
        class Document
        {
            public int $company_id = 1;

            // Attributes supplied by Akaunting's Eloquent models in production.
            public mixed $issued_at = null;
            public mixed $status = null;
            public mixed $contact_id = null;
            public mixed $contact_name = null;
            public mixed $contact_tax_number = null;
            public mixed $contact_address = null;
            public mixed $contact_zip_code = null;
            public mixed $contact_city = null;
            public mixed $contact_phone = null;
            public mixed $contact_email = null;
            public mixed $contact_country_code = null;
            public mixed $document_number = null;
            public mixed $amount_due = null;
            public mixed $currency_code = null;
            public mixed $company = null;
            public mixed $due_at = null;

            public function __construct(public int $id = 0, public float $amount = 0.0, public ?object $contact = null, public ?object $items = null, public string $description = "", public string $notes = "")
            {
                $this->items ??= new \App\Models\Sale\FakeCollection([]);
            } public static function invoice(): object
            {
                return new class () {
                    public function when(bool $condition, callable $callback): self
                    {
                        if ($condition) {
                            $callback($this);
                        } return $this;
                    } public function whereNotIn(string $column, array $values): self
                    {
                        return $this;
                    } public function where(mixed ...$args): self
                    {
                        if (($args[0] ?? null) instanceof \Closure) {
                            $args[0]($this);
                        } return $this;
                    } public function orWhereHas(string $relation, callable $callback): self
                    {
                        $callback($this);
                        return $this;
                    } public function latest(): self
                    {
                        return $this;
                    } public function paginate(int $perPage): array
                    {
                        return [];
                    }
                };
            }
        }
    }
}
