<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\DpsCompetencePreflight;
use Modules\Nfse\Application\FiscalIssuanceBlocked;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DpsCompetencePreflightTest extends TestCase
{
    public function testFutureCompetenceIsBlockedBeforeAllocatingAnyNumber(): void
    {
        $this->expectException(FiscalIssuanceBlocked::class);
        $this->expectExceptionMessage('11/11/2026');

        (new DpsCompetencePreflight())->assertCanAllocate('2026-11-11', '2026-11-10');
    }

    #[DataProvider('validDates')]
    public function testPresentAndPastCompetencesRemainValid(string $competence, string $issueDate): void
    {
        (new DpsCompetencePreflight())->assertCanAllocate($competence, $issueDate);
        self::assertTrue(true);
    }

    /** @return array<string,array{string,string}> */
    public static function validDates(): array
    {
        return [
            'same day' => ['2026-11-10', '2026-11-10'],
            'last month' => ['2026-10-01', '2026-11-10'],
            'leap day' => ['2024-02-29', '2026-11-10'],
        ];
    }

    #[DataProvider('defaultedCompetences')]
    public function testOmittedCompetenceDefaultsToEmissionDate(?string $competence): void
    {
        // XmlBuilder emits dCompet = dhEmi's date for these inputs.
        (new DpsCompetencePreflight())->assertCanAllocate($competence, '2026-11-10');
        self::assertTrue(true);
    }

    /** @return array<string,array{?string}> */
    public static function defaultedCompetences(): array
    {
        return ['null' => [null], 'empty string' => ['']];
    }

    #[DataProvider('invalidDates')]
    public function testInvalidExplicitCompetenceNeverGetsAReservation(string $date): void
    {
        $this->expectException(FiscalIssuanceBlocked::class);
        (new DpsCompetencePreflight())->assertCanAllocate($date, '2026-11-10');
    }

    /** @return array<string,array{string}> */
    public static function invalidDates(): array
    {
        return [
            'invalid calendar' => ['2026-02-30'],
            'invalid format' => ['10/11/2026'],
        ];
    }

    public function testInvalidIssueDateNeverGetsAReservation(): void
    {
        $this->expectException(FiscalIssuanceBlocked::class);
        (new DpsCompetencePreflight())->assertCanAllocate(null, '2026-02-30');
    }
}
