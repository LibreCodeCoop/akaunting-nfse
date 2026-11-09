<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

use Closure;
use InvalidArgumentException;

final class CustomerEmailPreferences
{
    private readonly Closure $reader;
    private readonly Closure $writer;

    public function __construct(?Closure $reader = null, ?Closure $writer = null)
    {
        $this->reader = $reader ?? static fn (string $key): mixed => setting($key, null);
        $this->writer = $writer ?? static function (array $values): void {
            setting($values);
            setting()->save();
        };
    }

    /** @return array{subject:string,body:string}|null */
    public function read(int $companyId, int $contactId, int $activeCompanyId): ?array
    {
        if (!$this->validScope($companyId, $contactId, $activeCompanyId)) {
            return null;
        }

        $subject = ($this->reader)(self::key($companyId, $contactId, 'subject'));
        $body = ($this->reader)(self::key($companyId, $contactId, 'body'));

        if (!is_string($subject) || trim($subject) === ''
            || !is_string($body) || trim(strip_tags($body)) === '') {
            return null;
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /** @return array{subject:string,body:string} */
    public function save(int $companyId, int $contactId, int $activeCompanyId, string $subject, string $body): array
    {
        if (!$this->validScope($companyId, $contactId, $activeCompanyId)) {
            throw new InvalidArgumentException('The email template contact must belong to the active company.');
        }

        $subject = trim((string) preg_replace('/[\r\n]+/', ' ', strip_tags($subject)));
        $body = strip_tags($body, '<p><br><div><strong><b><em><i><u><ol><ul><li>');
        $body = (string) preg_replace('/<([a-z][a-z0-9]*)(?:\s[^>]*)?>/i', '<$1>', $body);

        if ($subject === '' || trim(strip_tags($body)) === ''
            || mb_strlen($subject) > 255 || mb_strlen($body) > 12000) {
            throw new InvalidArgumentException('The email template requires a valid subject and body.');
        }

        ($this->writer)([
            self::key($companyId, $contactId, 'subject') => $subject,
            self::key($companyId, $contactId, 'body') => $body,
        ]);

        return ['subject' => $subject, 'body' => $body];
    }

    public static function key(int $companyId, int $contactId, string $field): string
    {
        if (!in_array($field, ['subject', 'body'], true)) {
            throw new InvalidArgumentException('Unsupported email preference field.');
        }

        return 'nfse.customer_email.' . $companyId . '.' . $contactId . '.' . $field;
    }

    private function validScope(int $companyId, int $contactId, int $activeCompanyId): bool
    {
        return $companyId > 0 && $contactId > 0 && $activeCompanyId === $companyId;
    }
}
