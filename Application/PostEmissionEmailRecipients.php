<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Normalizes one message's SMTP envelope. No invoice, company or global settings
 * are read here: the caller provides only the current invoice's addresses.
 */
final class PostEmissionEmailRecipients
{
    private const MAX_RECIPIENTS = 100;

    /**
     * @return array{to:list<string>,cc:list<string>,bcc:list<string>}
     */
    public function normalize(mixed $to, mixed $cc = null, mixed $bcc = null, ?string $copyToSelf = null): array
    {
        $recipients = [
            'to' => $this->parse($to, 'nfse_email_to'),
            'cc' => $this->parse($cc, 'nfse_email_cc'),
            'bcc' => $this->parse($bcc, 'nfse_email_bcc'),
        ];

        if ($recipients['to'] === []) {
            throw new \InvalidArgumentException('nfse_email_to');
        }

        if ($copyToSelf !== null && trim($copyToSelf) !== '') {
            $copy = $this->parse($copyToSelf, 'nfse_email_bcc');

            if (count($copy) !== 1) {
                throw new \InvalidArgumentException('nfse_email_bcc');
            }

            $recipients['bcc'][] = $copy[0];
        }

        // An address explicitly listed in To is visible. Duplicate addresses
        // in Cc/Bcc do not trigger extra deliveries or contradict that choice.
        $seen = [];

        foreach (['to', 'cc', 'bcc'] as $field) {
            $unique = [];

            foreach ($recipients[$field] as $address) {
                $key = strtolower($address);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $unique[] = $address;
            }

            $recipients[$field] = $unique;
        }

        if (count($seen) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException('nfse_email_to');
        }

        return $recipients;
    }

    /**
     * @return list<string>
     */
    private function parse(mixed $value, string $field): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_object($value) && isset($value->email)) {
            return $this->parse($value->email, $field);
        }

        if (is_array($value)) {
            if (array_key_exists('email', $value)) {
                return $this->parse($value['email'], $field);
            }

            $emails = [];

            foreach ($value as $item) {
                array_push($emails, ...$this->parse($item, $field));
            }

            return $emails;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException($field);
        }

        $value = trim($value);

        if ($value === '') {
            return [];
        }

        // Legacy Akaunting select components can serialize selections as JSON.
        if (str_starts_with($value, '[') || str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);

            if (!is_array($decoded)) {
                throw new \InvalidArgumentException($field);
            }

            return $this->parse($decoded, $field);
        }

        // Never treat line breaks as address delimiters: SMTP header injection
        // must be rejected in every input format, including nested arrays.
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException($field);
        }

        $emails = [];

        foreach (preg_split('/[;,]/', $value) ?: [] as $part) {
            $email = trim($part);

            if ($email === '') {
                continue;
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException($field);
            }

            $emails[] = strtolower($email);
        }

        return $emails;
    }
}
