<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Events\Common\CompanyMadeCurrent;
use App\Events\Common\CompanyMakingCurrent;
use App\Models\Common\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Nfse\Support\NfseCompanyJobContext;
use Tests\Feature\FeatureTestCase;

final class NfseCompanyJobContextTest extends FeatureTestCase
{
    public function testRestoresPreviousCompanyIfActivationThrowsAfterSwitch(): void
    {
        $this->loginAs();
        $previous = $this->company;
        $previous->makeCurrent();
        setting()->set(['company.email' => 'previous@example.test']);
        setting()->save();

        $owner = $this->owner();
        $owner->makeCurrent();
        setting()->set(['company.email' => 'owner@example.test']);
        setting()->save();
        $previous->makeCurrent();
        $this->throwDuringActivation((int) $owner->id);

        try {
            try {
                (new NfseCompanyJobContext())->run((int) $owner->id, static function (): void {
                    self::fail('The fiscal action must not execute after an activation failure.');
                });
                self::fail('Expected synthetic activation failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Synthetic owner activation failure.', $exception->getMessage());
            }

            self::assertSame((int) $previous->id, (int) Company::getCurrent()?->id);
            self::assertSame('previous@example.test', setting('company.email'));
        } finally {
            $previous->makeCurrent();
        }
    }

    public function testLeavesNoCompanyIfActivationThrowsWithEmptyWorkerContext(): void
    {
        $this->loginAs();
        $previous = $this->company;
        $owner = $this->owner();
        $this->throwDuringActivation((int) $owner->id);
        Company::forgetCurrent();

        try {
            try {
                (new NfseCompanyJobContext())->run((int) $owner->id, static function (): void {
                    self::fail('The fiscal action must not execute after an activation failure.');
                });
                self::fail('Expected synthetic activation failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Synthetic owner activation failure.', $exception->getMessage());
            }

            self::assertNull(Company::getCurrent());
        } finally {
            $previous->makeCurrent();
        }
    }

    public function testRestoresPreviousCompanyIfActivationFailsBeforeBinding(): void
    {
        $this->loginAs();
        $previous = $this->company;
        $previous->makeCurrent();
        setting()->set(['company.email' => 'previous@example.test']);
        setting()->save();
        $owner = $this->owner();

        Event::listen(CompanyMakingCurrent::class, static function (CompanyMakingCurrent $event) use ($owner): void {
            if ((int) $event->company->id === (int) $owner->id) {
                throw new \RuntimeException('Synthetic pre-binding activation failure.');
            }
        });

        try {
            try {
                (new NfseCompanyJobContext())->run((int) $owner->id, static function (): void {
                    self::fail('The fiscal action must not execute after an activation failure.');
                });
                self::fail('Expected pre-binding activation failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Synthetic pre-binding activation failure.', $exception->getMessage());
            }

            self::assertSame((int) $previous->id, (int) Company::getCurrent()?->id);
            self::assertSame('previous@example.test', setting('company.email'));
        } finally {
            $previous->makeCurrent();
        }
    }

    private function owner(): Company
    {
        $id = DB::table('companies')->insertGetId([
            'domain' => 'synthetic-job-context-owner.example.test',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Company::query()->findOrFail($id);
    }

    private function throwDuringActivation(int $ownerId): void
    {
        Event::listen(CompanyMadeCurrent::class, static function (CompanyMadeCurrent $event) use ($ownerId): void {
            if ((int) $event->company->id === $ownerId) {
                throw new \RuntimeException('Synthetic owner activation failure.');
            }
        });
    }
}
