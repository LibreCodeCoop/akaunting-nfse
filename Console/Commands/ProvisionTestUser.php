<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Console\Commands;

use App\Jobs\Auth\CreateUser;
use App\Jobs\Auth\UpdateUser;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProvisionTestUser extends Command
{
    protected $signature = 'nfse:test-user:provision
        {--email= : Email do usuario de teste}
        {--password= : Senha do usuario de teste}
        {--name=NFS-e E2E : Nome exibido para o usuario}
        {--company-id=1 : Company ID usada nos testes}
        {--role=admin : Nome ou ID da role usada no acesso}
        {--landing-page=dashboard : Landing page apos login}
        {--json : Emite um payload JSON para automacao}';

    protected $description = 'Cria ou atualiza um usuario fake para os testes do modulo NFS-e';

    public function handle(): int
    {
        $email = $this->optionString('email') ?: $this->generateEmail();
        $password = $this->optionString('password') ?: $this->generatePassword();
        $companyId = (int) ($this->option('company-id') ?? 1);

        $company = $this->findCompanyById($companyId);

        if ($company === null) {
            return $this->failWithMessage("Company {$companyId} nao encontrada.");
        }

        $role = $this->findRole($this->optionString('role'));

        if ($role === null) {
            return $this->failWithMessage('Nao foi possivel resolver a role informada para o usuario de teste.');
        }

        $payload = [
            'name' => $this->optionString('name') ?: 'NFS-e E2E',
            'email' => $email,
            'change_password' => true,
            'password' => $password,
            'password_confirmation' => $password,
            'companies' => [$company->getKey()],
            'roles' => (string) $role->getKey(),
            'landing_page' => $this->optionString('landing-page') ?: 'dashboard',
            'enabled' => '1',
        ];

        $user = $this->findUserByEmail($email);
        $created = $user === null;

        if ($created) {
            $user = $this->createUser($payload);
        } else {
            if (! $user instanceof Model) {
                return $this->failWithMessage('Nao foi possivel carregar o usuario de teste existente.');
            }

            $user = $this->updateUser($user, $payload);
        }

        $result = [
            'created' => $created,
            'company_id' => (string) $company->getKey(),
            'email' => $email,
            'password' => $password,
            'role_id' => (string) $role->getKey(),
            'user_id' => (string) $user->getKey(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $action = $created ? 'Created' : 'Updated';

        $this->info("{$action} NFSe test user {$email} for company {$company->getKey()}.");
        $this->line("Password: {$password}");

        return self::SUCCESS;
    }

    protected function optionString(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }

    protected function generateEmail(): string
    {
        return 'nfse-e2e+' . Str::lower(Str::ulid()) . '@example.test';
    }

    protected function generatePassword(): string
    {
        return 'NfseE2E!' . Str::random(18);
    }

    protected function findCompanyById(int $companyId): ?Model
    {
        return \App\Models\Common\Company::query()->find($companyId);
    }

    protected function findRole(string $roleOption): ?Model
    {
        $roleModelClass = role_model_class();
        $query = $roleModelClass::query();

        if ($roleOption !== '' && ctype_digit($roleOption)) {
            return $query->find((int) $roleOption);
        }

        if ($roleOption !== '') {
            $role = $query->where('name', $roleOption)->first();

            if ($role !== null) {
                return $role;
            }
        }

        return $roleModelClass::query()->whereIn('name', ['admin', 'manager'])->first()
            ?? $roleModelClass::query()->first();
    }

    protected function findUserByEmail(string $email): ?Model
    {
        $userModelClass = user_model_class();

        return $userModelClass::query()->where('email', $email)->first();
    }

    protected function createUser(array $payload): Model
    {
        /** @var Model $user */
        $user = (new CreateUser($payload))->handle();

        return $user;
    }

    protected function updateUser(Model $user, array $payload): Model
    {
        /** @var Model $updatedUser */
        $updatedUser = (new UpdateUser($user, $payload))->handle();

        return $updatedUser;
    }

    protected function failWithMessage(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['error' => $message], JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
