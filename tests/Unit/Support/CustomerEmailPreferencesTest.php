<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use InvalidArgumentException;
use Modules\Nfse\Support\CustomerEmailPreferences;
use PHPUnit\Framework\TestCase;

final class CustomerEmailPreferencesTest extends TestCase
{
    public function testSavedCustomerTemplateDoesNotAffectOtherContactOrCompany(): void
    {
        $values = [];
        $preferences = new CustomerEmailPreferences(
            static function (string $key) use (&$values): mixed {
                return $values[$key] ?? null;
            },
            static function (array $updated) use (&$values): void {
                $values = array_replace($values, $updated);
            },
        );

        $expected = [
            'subject' => 'NFS-e {nfse_number} emitida',
            'body' => '<p>Valor: {invoice_amount_due} — {invoice_due_date}</p>',
        ];
        self::assertSame($expected, $preferences->save(10, 21, 10, ...array_values($expected)));
        self::assertSame($expected, $preferences->read(10, 21, 10));
        self::assertNull($preferences->read(10, 22, 10));
        self::assertNull($preferences->read(11, 21, 11));
        self::assertNull($preferences->read(10, 21, 11));
        self::assertArrayNotHasKey('settings.email.templates.invoice_nfse_issued_customer', $values);
    }

    public function testRejectsCrossCompanyWriteWithoutPersistingAnything(): void
    {
        $writes = 0;
        $preferences = new CustomerEmailPreferences(
            static fn (string $key): mixed => null,
            static function (array $values) use (&$writes): void {
                $writes++;
            },
        );

        try {
            $preferences->save(10, 21, 11, 'Subject', '<p>Body</p>');
            self::fail('Cross-company preference must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $writes);
        }
    }

    public function testHtmlSanitizationPreservesTemplateTagsButRemovesActiveContent(): void
    {
        $preferences = new CustomerEmailPreferences(
            static fn (string $key): mixed => null,
            static function (array $values): void {
            },
        );

        $saved = $preferences->save(
            10,
            21,
            10,
            "Nota {nfse_number}\r\n<script>ignored</script>",
            '<p onclick="alert(1)">Total {invoice_amount_due}<br onload="evil()">'
            . '<img src=x onerror=evil()><strong>vence {invoice_due_date}</strong></p>',
        );
        self::assertStringNotContainsString('<script>', $saved['subject']);
        self::assertStringNotContainsString("\r", $saved['subject']);
        self::assertStringNotContainsString('onclick=', $saved['body']);
        self::assertStringNotContainsString('onload=', $saved['body']);
        self::assertStringNotContainsString('<img', $saved['body']);
        self::assertStringContainsString('{invoice_amount_due}', $saved['body']);
        self::assertStringContainsString('{invoice_due_date}', $saved['body']);
    }

    public function testRejectsEmptyTemplateAndUnknownSettingKeys(): void
    {
        $preferences = new CustomerEmailPreferences(
            static fn (string $key): mixed => null,
            static function (array $values): void {
            },
        );
        $this->expectException(InvalidArgumentException::class);
        $preferences->save(10, 21, 10, 'Subject', '<p><br></p>');
    }
}
