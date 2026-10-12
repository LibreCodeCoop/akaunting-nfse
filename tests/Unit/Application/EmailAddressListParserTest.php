<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\EmailAddressListParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailAddressListParserTest extends TestCase
{
    public function testAcceptsNativeContactsAndManualDelimitedAddresses(): void
    {
        $actual = (new EmailAddressListParser())->parse([
            ['email' => 'one@example.test'],
            (object) ['email' => 'TWO@example.test'],
            'three@example.test; four@example.test',
        ], 'nfse_email_to');

        self::assertSame([
            'one@example.test',
            'two@example.test',
            'three@example.test',
            'four@example.test',
        ], $actual);
    }

    public function testAcceptsLegacyJsonArrayOfSelectedContacts(): void
    {
        $actual = (new EmailAddressListParser())->parse(
            '[{"email":"first@example.test"},{"email":"second@example.test"}]',
            'nfse_email_to',
        );

        self::assertSame(['first@example.test', 'second@example.test'], $actual);
    }

    public function testEmptyOptionalFieldIsAnEmptyList(): void
    {
        self::assertSame([], (new EmailAddressListParser())->parse(null, 'nfse_email_bcc'));
        self::assertSame([], (new EmailAddressListParser())->parse('', 'nfse_email_bcc'));
        self::assertSame([], (new EmailAddressListParser())->parse([], 'nfse_email_bcc'));
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsMalformedPayloadsWithoutRecursiveFallback(mixed $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nfse_email_bcc');

        (new EmailAddressListParser())->parse($input, 'nfse_email_bcc');
    }

    public static function invalidInputs(): array
    {
        return [
            'newline/header injection' => ["hidden@example.test\r\nBcc: leak@example.test"],
            'invalid mail syntax' => ['not-an-email'],
            'truncated json' => ['[{"email":'],
            'nested list' => [[[['email' => 'hidden@example.test']]]],
            'unsupported number' => [123],
            'invalid contact value' => [['email' => ['hidden@example.test']]],
            'associative unrelated structure' => [['unrelated' => 'hidden@example.test']],
        ];
    }
}
