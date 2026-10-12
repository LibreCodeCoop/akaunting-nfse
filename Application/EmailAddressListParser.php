<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Translates the small set of supported form payloads into validated addresses.
 * Transport and delivery policy are deliberately outside this class.
 */
final class EmailAddressListParser
{
    /**
     * @return list<string>
     */
    public function parse(mixed $input, string $field): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        if (is_string($input) && in_array(substr(ltrim($input), 0, 1), ['[', '{'], true)) {
            $input = json_decode($input, true);

            if (!is_array($input)) {
                throw new \InvalidArgumentException($field);
            }
        }

        if (is_string($input) || is_object($input) || (is_array($input) && array_key_exists('email', $input))) {
            $input = [$input];
        }

        if (!is_array($input) || !array_is_list($input)) {
            throw new \InvalidArgumentException($field);
        }

        $addresses = [];

        foreach ($input as $item) {
            $value = $this->addressValue($item, $field);

            if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new \InvalidArgumentException($field);
            }

            foreach (preg_split('/[,;]/', $value) ?: [] as $email) {
                $email = trim($email);

                if ($email === '') {
                    continue;
                }

                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \InvalidArgumentException($field);
                }

                $addresses[] = strtolower($email);
            }
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
