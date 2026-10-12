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
    #[DataProvider('recipientCombinations')]
    public function testRecipientRolesAndDeduplication(
        mixed $to,
        mixed $cc,
        mixed $bcc,
        ?string $copyToSelf,
        array $expected,
    ): void {
        self::assertSame($expected, (new PostEmissionEmailRecipients())->normalize($to, $cc, $bcc, $copyToSelf));
    }

    public static function recipientCombinations(): array
    {
        return [
            'one primary recipient' => [
                'one@example.test', null, null, null,
                ['to' => ['one@example.test'], 'cc' => [], 'bcc' => []],
            ],
            'all three roles and multiple recipients' => [
                [
                    ['email' => 'first@example.test'],
                    (object) ['email' => 'second@example.test'],
                    'third@example.test; fourth@example.test',
                ],
                'visible@example.test, visible2@example.test',
                ['hidden@example.test'],
                null,
                [
                    'to' => ['first@example.test', 'second@example.test', 'third@example.test', 'fourth@example.test'],
                    'cc' => ['visible@example.test', 'visible2@example.test'],
                    'bcc' => ['hidden@example.test'],
                ],
            ],
            'first visible role wins duplicates' => [
                'FIRST@example.test, first@example.test',
                ['First@example.test', 'copy@example.test'],
                'COPY@example.test, hidden@example.test',
                'hidden@example.test',
                ['to' => ['first@example.test'], 'cc' => ['copy@example.test'], 'bcc' => ['hidden@example.test']],
            ],
            'self-copy is private' => [
                'client@example.test', null, null, 'me@example.test',
                ['to' => ['client@example.test'], 'cc' => [], 'bcc' => ['me@example.test']],
            ],
            'self-copy already in To is not duplicated' => [
                'ME@example.test', null, null, 'me@example.test',
                ['to' => ['me@example.test'], 'cc' => [], 'bcc' => []],
            ],
            'self-copy already in Cc is not duplicated' => [
                'client@example.test', ['me@example.test'], null, 'ME@example.test',
                ['to' => ['client@example.test'], 'cc' => ['me@example.test'], 'bcc' => []],
            ],
            'self-copy already in Bcc is not duplicated' => [
                'client@example.test', null, ['me@example.test'], 'ME@example.test',
                ['to' => ['client@example.test'], 'cc' => [], 'bcc' => ['me@example.test']],
            ],
            'legacy JSON preserves selection order' => [
                '[{"email":"first@example.test"},{"email":"second@example.test"}]',
                'third@example.test',
                null,
                null,
                ['to' => ['first@example.test', 'second@example.test'], 'cc' => ['third@example.test'], 'bcc' => []],
            ],
        ];
    }

    #[DataProvider('invalidRoles')]
    public function testRejectsInvalidRolePayloads(mixed $to, mixed $cc, mixed $bcc, ?string $self, string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($field);

        (new PostEmissionEmailRecipients())->normalize($to, $cc, $bcc, $self);
    }

    public static function invalidRoles(): array
    {
        return [
            'missing primary' => [null, null, null, null, 'nfse_email_to'],
            'blank primary' => [' , ; ', 'copy@example.test', null, null, 'nfse_email_to'],
            'invalid primary' => ['not-an-email', null, null, null, 'nfse_email_to'],
            'invalid Cc' => ['client@example.test', 'bad-address', null, null, 'nfse_email_cc'],
            'invalid Bcc' => ['client@example.test', null, "hidden@example.test\r\nBcc: leak@example.test", null, 'nfse_email_bcc'],
            'invalid legacy Bcc JSON' => ['client@example.test', null, '[{"email":', null, 'nfse_email_bcc'],
            'invalid self-copy email' => ['client@example.test', null, null, 'not-an-email', 'nfse_email_bcc'],
            'empty self-copy after trimming' => ['client@example.test', null, null, ' ', 'nfse_email_bcc'],
            'multiple self-copy addresses' => ['client@example.test', null, null, 'one@example.test;two@example.test', 'nfse_email_bcc'],
        ];
    }

    public function testExactlyOneHundredUniqueRecipientsAreAllowed(): void
    {
        $to = array_map(static fn (int $n): string => "client{$n}@example.test", range(1, 100));
        $actual = (new PostEmissionEmailRecipients())->normalize($to, $to, $to);

        self::assertSame($to, $actual['to']);
        self::assertSame([], $actual['cc']);
        self::assertSame([], $actual['bcc']);
    }

    public function testRecipientLimitAppliesAcrossAllRolesAfterDeduplication(): void
    {
        $to = array_map(static fn (int $n): string => "client{$n}@example.test", range(1, 60));
        $cc = array_map(static fn (int $n): string => "copy{$n}@example.test", range(1, 41));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nfse_email_to');

        (new PostEmissionEmailRecipients())->normalize($to, $cc);
    }

    public function testNormalizingAnAlreadyNormalizedDeliveryKeepsItsRecipients(): void
    {
        $normalizer = new PostEmissionEmailRecipients();
        $first = $normalizer->normalize('PRIMARY@example.test', 'copy@example.test', 'hidden@example.test');

        self::assertSame($first, $normalizer->normalize($first['to'], $first['cc'], $first['bcc']));
    }
}
