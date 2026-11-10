<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\ContactEmailCcDefaults;
use PHPUnit\Framework\TestCase;

final class ContactEmailCcDefaultsTest extends TestCase
{
    public function testItSuggestsValidUniqueSecondaryEmailsWithoutRepeatingThePrimary(): void
    {
        $contact = new class () {
            public string $email = 'billing@example.test';

            public function withPersons(): array
            {
                return [
                    $this,
                    (object) ['email' => ' Igor@Example.test '],
                    (object) ['email' => 'igor@example.test'],
                    (object) ['email' => 'BILLING@example.test'],
                    (object) ['email' => 'invalid address'],
                    (object) ['email' => 'another@example.test'],
                    (object) ['email' => null],
                ];
            }
        };

        self::assertSame(
            'Igor@Example.test, another@example.test',
            (new ContactEmailCcDefaults())->fromContact($contact, 'billing@example.test'),
        );
    }

    public function testItDoesNotTreatThePrimaryContactAsASecondaryRecipient(): void
    {
        $contact = new class () {
            public string $email = 'billing@example.test';

            public function withPersons(): array
            {
                return [$this];
            }
        };

        self::assertSame('', (new ContactEmailCcDefaults())->fromContact($contact, ''));
    }

    public function testItAllowsContactsWithoutSecondaryPeople(): void
    {
        self::assertSame('', (new ContactEmailCcDefaults())->fromContact(null, 'billing@example.test'));
        self::assertSame('', (new ContactEmailCcDefaults())->fromContact(
            (object) ['email' => 'billing@example.test'],
            'billing@example.test',
        ));
    }

    public function testItCapsSuggestionsBeforeReachingTheDeliveryLimit(): void
    {
        $contact = new class () {
            public function withPersons(): array
            {
                return array_map(
                    static fn (int $index): object => (object) ['email' => 'person' . $index . '@example.test'],
                    range(1, 110),
                );
            }
        };

        $result = (new ContactEmailCcDefaults())->fromContact($contact, 'billing@example.test');
        self::assertCount(98, explode(', ', $result));
        self::assertStringEndsWith('person98@example.test', $result);
    }
}
