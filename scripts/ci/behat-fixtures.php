<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Run with artisan tinker --execute against a newly provisioned testing SQLite
 * database only. The canonical NFS-e harness creates synthetic certificates.
 * This file adds only the native Akaunting tenant and ACL test actors.
 */
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Common\Company;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Run as a standalone CLI script. Unlike tinker --execute, uncaught
// exceptions must result in a non-zero CI exit status.
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
$first = Company::query()->find(1);
if (!$first instanceof Company) {
    throw new RuntimeException('Seed the synthetic first Akaunting company before Behat fixtures.');
}

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
if (Artisan::call('company:seed', ['company' => $otherId]) !== 0) {
    throw new RuntimeException('Failed to seed the second native Akaunting company.');
}

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

$invoke('nfse:test-harness:provision', ['--company-id' => (string) $otherId]);
$invoke('nfse:test-user:provision', [
    '--company-id' => (string) $otherId,
    '--email' => 'nfse-behat-other@example.test',
    '--password' => 'NfseBehatOther!123456',
    '--role' => 'admin',
    '--json' => true,
]);

$first->makeCurrent();
$role = Role::query()->firstOrCreate(
    ['name' => 'nfse-behat-denied'],
    ['display_name' => 'NFS-e denied test role', 'description' => 'Synthetic ACL negative fixture'],
);
if ($role->permissions()->exists()) {
    throw new RuntimeException('Denied fixture role must not hold permissions.');
}
$invoke('nfse:test-user:provision', [
    '--company-id' => '1',
    '--email' => 'nfse-behat-denied@example.test',
    '--password' => 'NfseBehatDenied!123456',
    '--role' => 'nfse-behat-denied',
    '--json' => true,
]);

$firstUser = User::query()->where('email', 'nfse-e2e@example.test')->firstOrFail();
$otherUser = User::query()->where('email', 'nfse-behat-other@example.test')->firstOrFail();
$deniedUser = User::query()->where('email', 'nfse-behat-denied@example.test')->firstOrFail();
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

$envFile = getenv('GITHUB_ENV');
if (!is_string($envFile) || $envFile === '' || !is_file($envFile)) {
    throw new RuntimeException('GITHUB_ENV is required for the disposable CI fixture.');
}
if (file_put_contents($envFile, "NFSE_BEHAT_OTHER_COMPANY_ID={$otherId}\n", FILE_APPEND | LOCK_EX) === false) {
    throw new RuntimeException('Unable to publish the synthetic second company ID.');
}
echo "Behat synthetic company memberships and permission fixtures verified.\n";
