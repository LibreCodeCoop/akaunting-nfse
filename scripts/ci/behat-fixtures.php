<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Run directly with PHP against a disposable SQLite testing database only.
 * The canonical NFS-e harness creates synthetic certificates.
 * This file adds only the native Akaunting tenant and ACL test actors.
 */
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Common\Company;
use App\Models\Common\Contact;
use App\Models\Document\Document;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Models\NfseCompanyProfile;
use Modules\Nfse\Support\NfseCompanyFiscalReadiness;

// Run as a standalone CLI script. Unlike tinker --execute, uncaught
// exceptions must result in a non-zero CI exit status.
$fixtureStage = 'bootstrap';
try {
    $coreRoot = dirname(__DIR__, 4);
    if (!is_file($coreRoot . '/artisan') || !is_file($coreRoot . '/vendor/autoload.php')) {
        throw new RuntimeException('Disposable Akaunting checkout is required.');
    }
    require_once $coreRoot . '/vendor/autoload.php';
    $app = require $coreRoot . '/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    if (!app()->environment('testing')
        || DB::connection()->getDriverName() !== 'sqlite'
        || getenv('NFSE_TEST_HARNESS') !== '1') {
        throw new LogicException('Behat fixtures require a disposable testing SQLite environment.');
    }
    $actors = json_decode(
        file_get_contents(__DIR__ . '/behat-actors.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    foreach (['administrator', 'other-company-administrator', 'no-permission'] as $actor) {
        if (!is_string($actors[$actor]['email'] ?? null)
            || !str_ends_with($actors[$actor]['email'], '@example.test')
            || !is_string($actors[$actor]['password'] ?? null)
            || $actors[$actor]['password'] === '') {
            throw new RuntimeException('Behat test accounts must be nonempty and synthetic.');
        }
    }
    $first = Company::query()->find(1);
    if (!$first instanceof Company) {
        throw new RuntimeException('Seed the synthetic first Akaunting company before Behat fixtures.');
    }

    $fixtureStage = 'create-second-company';
    $other = Company::query()->firstOrCreate(
        ['domain' => 'behat-other.invalid'],
        ['enabled' => 1, 'created_from' => 'core::seed'],
    );
    $otherId = (int) $other->id;
    if ($otherId === 1) {
        throw new RuntimeException('Synthetic second company must be distinct.');
    }

    $other->makeCurrent();
    // Company seeders may send messages: initialize required native settings first.
    setting()->set([
        'company.name' => 'Behat other company',
        'company.email' => 'other@behat.invalid',
        'default.currency' => 'USD',
        'default.locale' => 'en-GB',
        'email.protocol' => 'array',
    ]);
    setting()->save();
    // Switching companies applies settings before this fixture initializes the
    // new company's email. Reload native overrides before calling core seeders.
    \App\Utilities\Overrider::load('settings');
    if (config('mail.from.address') !== 'other@behat.invalid') {
        throw new RuntimeException('Synthetic company mail configuration did not load.');
    }

    $fixtureStage = 'seed-second-company';
    if (Artisan::call('company:seed', ['company' => $otherId]) !== 0) {
        throw new RuntimeException('Failed to seed the second native Akaunting company.');
    }

    $fixtureStage = 'install-module-second-company';
    if (Artisan::call('module:install', [
        'alias' => 'nfse',
        'company' => $otherId,
        'locale' => 'en-GB',
    ]) !== 0) {
        throw new RuntimeException('Failed to install NFS-e into the synthetic second company.');
    }

    $invoke = static function (string $name, array $parameters): void {
        if (Artisan::call($name, $parameters) !== 0) {
            throw new RuntimeException('Synthetic fixture command failed: ' . $name);
        }
    };

    $fixtureStage = 'provision-second-fiscal-profile';
    $invoke('nfse:test-harness:provision', ['--company-id' => (string) $otherId]);
    $fixtureStage = 'provision-second-user';
    $invoke('nfse:test-user:provision', [
        '--company-id' => (string) $otherId,
        '--email' => $actors['other-company-administrator']['email'],
        '--password' => $actors['other-company-administrator']['password'],
        '--role' => 'admin',
        '--json' => true,
    ]);

    $fixtureStage = 'finish-native-company-wizard';
    // Akaunting's native company seeder initializes wizard.completed=0.
    // The HTTP middleware redirects every company-two route until its own
    // synthetic company setup is explicitly complete.
    $other->makeCurrent();
    setting()->set('wizard.completed', '1');
    setting()->save();

    $fixtureStage = 'provision-ready-emission-fixture';
    $first->makeCurrent();
    // Same official local/testing fiscal harness; real SEFIN transport is
    // never bound while NFSE_TEST_HARNESS is enabled.
    $invoke('nfse:test-harness:provision', [
        '--company-id' => '1',
        '--bulk-emission-fixture' => true,
        '--json' => true,
    ]);
    $readyInvoice = Document::query()
        ->where('company_id', 1)
        ->where('type', 'invoice')
        ->where('document_number', 'NFSE-E2E-BULK-READY')
        ->firstOrFail();

    $fixtureStage = 'complete-synthetic-emission-readiness';
    // The shared test harness establishes certificate identity and issuer tax
    // mode, while the browser bulk fixture tests preflight presentation only.
    // For an actual HTTP issuance, the disposable tenant must additionally
    // make the explicit regulatory choices that production never guesses.
    $profile = NfseCompanyProfile::query()->where('company_id', 1)->firstOrFail();
    $fiscal = $profile->fiscal_parameters ?? [];
    $profile->forceFill(['fiscal_parameters' => array_merge($fiscal, [
        'uf' => 'RJ',
        'tributacao_issqn' => '1',
        'tipo_retencao_iss' => '2',
    ])])->save();

    $readiness = (new NfseCompanyFiscalReadiness())->evaluate($profile->fresh()->publicData());
    if (!$readiness['basic_complete']) {
        throw new RuntimeException('Synthetic fiscal profile must be ready before HTTP issuance.');
    }
    // Use only fictitious contact details. Do not weaken the production
    // requirement for a populated, tenant-owned service taker.
    $contact = Contact::query()
        ->where('company_id', 1)
        ->whereKey((int) $readyInvoice->contact_id)
        ->firstOrFail();
    $contact->forceFill([
        'country' => 'BR',
        'state' => 'RJ',
        'city' => '3303302',
        'zip_code' => '24020040',
        'address' => 'Rua Sintetica, 10 - Centro',
    ])->save();

    $fixtureStage = 'verify-restricted-user';
    // The existing pending-invoice harness already provides a restricted operator
    // with access to the admin shell but no NFS-e / invoice permissions. Reuse it.
    $role = Role::query()->where('name', 'nfse-e2e-restricted')->firstOrFail();
    if ($role->hasPermission('read-nfse-settings')
        || $role->hasPermission('update-nfse-settings')
        || $role->hasPermission('update-sales-invoices')) {
        throw new RuntimeException('Existing restricted fixture unexpectedly has fiscal permissions.');
    }

    $firstUser = User::query()->where('email', $actors['administrator']['email'])->firstOrFail();
    $otherUser = User::query()->where('email', $actors['other-company-administrator']['email'])->firstOrFail();
    $deniedUser = User::query()->where('email', $actors['no-permission']['email'])->firstOrFail();
    foreach ([$firstUser, $deniedUser] as $member) {
        if ($member->companies()->whereKey($otherId)->exists()) {
            throw new RuntimeException('Unexpected access to the synthetic second company.');
        }
    }
    if (!$otherUser->companies()->whereKey($otherId)->exists() || $otherUser->companies()->whereKey(1)->exists()) {
        throw new RuntimeException('Second synthetic account company membership is incorrect.');
    }
    if (!$deniedUser->roles()->whereKey($role->id)->exists()) {
        throw new RuntimeException('Denied fixture user does not possess the expected role.');
    }

    $fixtureStage = 'report-fixtures';
    // Only numeric test IDs leave this process. No GitHub-specific environment
    // file or secret is required; the caller passes IDs to its test process.
    echo json_encode([
        'other_company_id' => $otherId,
        'ready_invoice_id' => (int) $readyInvoice->id,
    ], JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $exception) {
    // CI diagnostics may identify the failure stage and exception class, never
    // secret material or exception values supplied by untrusted fixtures.
    fwrite(STDERR, sprintf(
        "Synthetic Behat fixture failed during %s (%s).\n",
        $fixtureStage,
        $exception::class,
    ));
    exit(1);
}
