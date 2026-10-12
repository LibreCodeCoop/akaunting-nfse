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
    #[DataProvider('supportedPayloads')]
    public function testParsesSupportedFormPayloads(mixed $input, array $expected): void
    {
        self::assertSame($expected, (new EmailAddressListParser())->parse($input, 'nfse_email_to'));
    }

    public static function supportedPayloads(): array
    {
        return [
            'single email' => ['single@example.test', ['single@example.test']],
            'surrounding spaces and case' => [' FIRST@Example.Test ', ['first@example.test']],
            'comma and semicolon delimiters' => [
                'one@example.test; two@example.test, three@example.test',
                ['one@example.test', 'two@example.test', 'three@example.test'],
            ],
            'selected contact array' => [['email' => 'one@example.test'], ['one@example.test']],
            'selected contact object' => [(object) ['email' => 'one@example.test'], ['one@example.test']],
            'legacy contact JSON object' => ['{"email":"one@example.test"}', ['one@example.test']],
            'legacy contact JSON list' => [
                '[{"email":"one@example.test"},{"email":"two@example.test"}]',
                ['one@example.test', 'two@example.test'],
            ],
            'combined contacts and manual addresses' => [
                [
                    ['email' => 'one@example.test'],
                    (object) ['email' => 'TWO@example.test'],
                    'three@example.test; four@example.test',
                ],
                ['one@example.test', 'two@example.test', 'three@example.test', 'four@example.test'],
            ],
            'empty null' => [null, []],
            'empty string' => ['', []],
            'empty list' => [[], []],
            'empty JSON array' => ['[]', []],
            'empty JSON object' => ['{}', []],
            'only empty delimiters' => [' , ; ', []],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidFormPayloads(mixed $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nfse_email_bcc');

        (new EmailAddressListParser())->parse($input, 'nfse_email_bcc');
    }

    public static function invalidPayloads(): array
    {
        return [
            'CRLF injection' => ["hidden@example.test\r\nBcc: leak@example.test"],
            'tab control character' => ["hidden@example.test\t"],
            'null byte' => ["hidden@example.test\0"],
            'missing at sign' => ['invalid-email'],
            'mixed valid and invalid tokens' => ['valid@example.test; invalid'],
            'malformed JSON' => ['[{"email":'],
            'nested list' => [[[['email' => 'hidden@example.test']]]],
            'number' => [123],
            'boolean' => [false],
            'invalid selected contact type' => [['email' => ['hidden@example.test']]],
            'missing selected contact email' => [['unknown' => 'hidden@example.test']],
            'object without email' => [(object) ['name' => 'Synthetic']],
            'non-string contact email' => [(object) ['email' => 123]],
            'JSON object with invalid contact type' => ['{"email":123}'],
            'JSON list containing non-address' => ['["valid@example.test",true]'],
        ];
    }
}
