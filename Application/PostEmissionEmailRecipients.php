<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Defines one message's recipient roles and uniqueness across To, Cc and Bcc.
 * Parsing of individual fields is delegated to EmailAddressListParser.
 */
final class PostEmissionEmailRecipients
{
    private const MAX_RECIPIENTS = 100;

    public function __construct(
        private readonly EmailAddressListParser $parser = new EmailAddressListParser(),
    ) {
    }

    /** @return array{to:list<string>,cc:list<string>,bcc:list<string>} */
    public function normalize(mixed $to, mixed $cc = null, mixed $bcc = null, ?string $copyToSelf = null): array
    {
        $groups = [
            'to' => $this->parser->parse($to, 'nfse_email_to'),
            'cc' => $this->parser->parse($cc, 'nfse_email_cc'),
            'bcc' => $this->parser->parse($bcc, 'nfse_email_bcc'),
        ];

        if ($groups['to'] === []) {
            throw new \InvalidArgumentException('nfse_email_to');
        }

        $groups['bcc'] = $this->withSelfCopy($groups['bcc'], $copyToSelf);

        return $this->uniqueGroups($groups);
    }

    /**
     * @param list<string> $bcc
     * @return list<string>
     */
    private function withSelfCopy(array $bcc, ?string $copyToSelf): array
    {
        if ($copyToSelf === null || $copyToSelf === '') {
            return $bcc;
        }

        $self = $this->parser->parse($copyToSelf, 'nfse_email_bcc');

        if (count($self) !== 1) {
            throw new \InvalidArgumentException('nfse_email_bcc');
        }

        $bcc[] = $self[0];

        return $bcc;
    }

    /**
     * @param array{to:list<string>,cc:list<string>,bcc:list<string>} $groups
     * @return array{to:list<string>,cc:list<string>,bcc:list<string>}
     */
    private function uniqueGroups(array $groups): array
    {
        $seen = [];
        $unique = ['to' => [], 'cc' => [], 'bcc' => []];

        // Preserve first occurrence, with To > Cc > Bcc precedence.
        foreach ($groups as $field => $addresses) {
            foreach ($addresses as $address) {
                if (isset($seen[$address])) {
                    continue;
                }

                $unique[$field][] = $address;
                $seen[$address] = true;
            }
        }

        if (count($seen) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException('nfse_email_to');
        }

        return $unique;
    }
}
