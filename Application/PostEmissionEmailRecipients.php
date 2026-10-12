<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * One message, one delivery: normalize and deduplicate To, Cc and Bcc.
 * Addresses are parsed independently by EmailAddressListParser.
 */
final class PostEmissionEmailRecipients
{
    private const MAX_RECIPIENTS = 100;

    public function __construct(
        private readonly EmailAddressListParser $parser = new EmailAddressListParser(),
    ) {
    }

    /**
     * @return array{to:list<string>,cc:list<string>,bcc:list<string>}
     */
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

        if ($copyToSelf !== null && $copyToSelf !== '') {
            $self = $this->parser->parse($copyToSelf, 'nfse_email_bcc');

            if (count($self) !== 1) {
                throw new \InvalidArgumentException('nfse_email_bcc');
            }

            $groups['bcc'][] = $self[0];
        }

        $seen = [];
        $uniqueGroups = [];

        // Priority To > Cc > Bcc prevents duplicate SMTP envelope recipients.
        foreach ($groups as $field => $addresses) {
            $uniqueGroups[$field] = [];

            foreach ($addresses as $address) {
                if (isset($seen[$address])) {
                    continue;
                }

                $uniqueGroups[$field][] = $address;
                $seen[$address] = true;
            }
        }

        if (count($seen) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException('nfse_email_to');
        }

        return $uniqueGroups;
    }
}
