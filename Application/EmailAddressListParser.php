<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Parses supported form payloads into validated email addresses.
 * No delivery, deduplication or recipient-role policy belongs here.
 */
final class EmailAddressListParser
{
    /** @return list<string> */
    public function parse(mixed $input, string $field): array
    {
        $addresses = [];

        foreach ($this->items($input, $field) as $item) {
            foreach ($this->validatedAddresses($this->addressValue($item, $field), $field) as $address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /** @return list<mixed> */
    private function items(mixed $input, string $field): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        $input = $this->decodeLegacyJson($input, $field);

        if (is_string($input) || is_object($input) || (is_array($input) && array_key_exists('email', $input))) {
            return [$input];
        }

        if (!is_array($input) || !array_is_list($input)) {
            throw new \InvalidArgumentException($field);
        }

        return $input;
    }

    private function decodeLegacyJson(mixed $input, string $field): mixed
    {
        if (!is_string($input) || !in_array(substr(ltrim($input), 0, 1), ['[', '{'], true)) {
            return $input;
        }

        $decoded = json_decode($input, true);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException($field);
        }

        return $decoded;
    }

    /** @return list<string> */
    private function validatedAddresses(string $value, string $field): array
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException($field);
        }

        $addresses = [];

        foreach (preg_split('/[,;]/', $value) ?: [] as $part) {
            $email = trim($part);

            if ($email === '') {
                continue;
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException($field);
            }

            $addresses[] = strtolower($email);
        }

        return $addresses;
    }

    private function addressValue(mixed $item, string $field): string
    {
        if (is_string($item)) {
            return $item;
        }

        if (is_object($item) && isset($item->email) && is_string($item->email)) {
            return $item->email;
        }

        if (is_array($item) && isset($item['email']) && is_string($item['email'])) {
            return $item['email'];
        }

        throw new \InvalidArgumentException($field);
    }
}
