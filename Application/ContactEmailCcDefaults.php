<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Suggests Cc recipients from the customer contact's native contact persons.
 * The user remains free to edit or remove the suggestion before sending.
 */
final class ContactEmailCcDefaults
{
    public function fromContact(?object $contact, string $primaryEmail): string
    {
        if ($contact === null || !is_callable([$contact, 'withPersons'])) {
            return '';
        }

        $seen = [];
        if ($primaryEmail !== '') {
            $seen[strtolower(trim($primaryEmail))] = true;
        }

        $cc = [];
        foreach ($contact->withPersons() as $person) {
            if ($person === $contact) {
                continue;
            }

            $email = trim((string) ($person->email ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $cc[] = $email;

            // The delivery contract accepts at most 100 unique addresses.
            // Reserve two places for To and an optional copy to the sender.
            if (count($cc) === 98) {
                break;
            }
        }

        return implode(', ', $cc);
    }
}
