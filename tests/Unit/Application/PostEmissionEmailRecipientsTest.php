<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\PostEmissionEmailRecipients;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostEmissionEmailRecipientsTest extends TestCase
{
    public function testMultipleContactsAndManualAddressesAreNotLost(): void
    {
        $actual = (new PostEmissionEmailRecipients())->normalize(
            [
                ['email' => 'first@example.test'],
                (object) ['email' => 'second@example.test'],
                'third@example.test; fourth@example.test',
            ],
            'visible@example.test, visible2@example.test',
            ['hidden@example.test'],
        );

        self::assertSame(
            ['first@example.test', 'second@example.test', 'third@example.test', 'fourth@example.test'],
            $actual['to'],
        );
        self::assertSame(['visible@example.test', 'visible2@example.test'], $actual['cc']);
        self::assertSame(['hidden@example.test'], $actual['bcc']);
    }

    public function testDuplicateRecipientsAppearOnlyOnceWithVisibleAddressPrecedence(): void
    {
        $actual = (new PostEmissionEmailRecipients())->normalize(
            'FIRST@example.test, first@example.test',
            ['First@example.test', 'copy@example.test'],
            'COPY@example.test, hidden@example.test',
            'hidden@example.test',
        );

        self::assertSame(['first@example.test'], $actual['to']);
        self::assertSame(['copy@example.test'], $actual['cc']);
        self::assertSame(['hidden@example.test'], $actual['bcc']);
    }

    public function testSelfCopyIsAddedAsPrivateRecipient(): void
    {
        $actual = (new PostEmissionEmailRecipients())->normalize('client@example.test', null, null, 'me@example.test');

        self::assertSame(['me@example.test'], $actual['bcc']);
        self::assertSame(['client@example.test'], $actual['to']);
    }

    public function testAcceptsLegacyJsonArrayOfAddresses(): void
    {
        $actual = (new PostEmissionEmailRecipients())->normalize(
            '[{"email":"first@example.test"},{"email":"second@example.test"}]',
        );

        self::assertSame(['first@example.test', 'second@example.test'], $actual['to']);
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsInvalidValuesBeforeSending(mixed $raw, string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($field);

        (new PostEmissionEmailRecipients())->normalize(
            'client@example.test',
            $field === 'nfse_email_cc' ? $raw : null,
            $field === 'nfse_email_bcc' ? $raw : null,
            null,
        );
    }

    public static function invalidAddresses(): array
    {
        return [
            'invalid visible copy' => ['not-an-email', 'nfse_email_cc'],
            'hidden header injection' => ["hidden@example.test\r\nBcc: other@example.test", 'nfse_email_bcc'],
            'invalid json' => ['[{"email":', 'nfse_email_bcc'],
            'nested invalid object' => [[['email' => 'invalid']], 'nfse_email_bcc'],
        ];
    }

    public function testMissingPrimaryRecipientIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nfse_email_to');

        (new PostEmissionEmailRecipients())->normalize(' ', 'visible@example.test', 'hidden@example.test');
    }

    public function testCapsTotalRecipients(): void
    {
        $addresses = [];

        for ($i = 0; $i < 101; $i++) {
            $addresses[] = "client{$i}@example.test";
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nfse_email_to');

        (new PostEmissionEmailRecipients())->normalize($addresses);
    }
}
