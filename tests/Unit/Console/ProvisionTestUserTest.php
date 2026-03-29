<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Illuminate\Console {
    if (!class_exists(Command::class, false)) {
        class Command
        {
            public const SUCCESS = 0;
            public const FAILURE = 1;

            /** @var array<string, mixed> */
            protected array $testOptions = [];

            /** @var list<string> */
            public array $outputLines = [];

            protected function option(string $name): mixed
            {
                return $this->testOptions[$name] ?? null;
            }

            public function line(string $string): void
            {
                $this->outputLines[] = $string;
            }

            public function info(string $string): void
            {
                $this->outputLines[] = $string;
            }

            public function error(string $string): void
            {
                $this->outputLines[] = $string;
            }
        }
    }
}

namespace Illuminate\Database\Eloquent {
    if (!class_exists(Model::class, false)) {
        class Model
        {
            public function __construct(private int|string $key = 1)
            {
            }

            public function getKey(): int|string
            {
                return $this->key;
            }
        }
    }
}

namespace Illuminate\Support {
    if (!class_exists(Str::class, false)) {
        class Str
        {
            public static function ulid(): string
            {
                return '01TESTULID';
            }

            public static function lower(string $value): string
            {
                return strtolower($value);
            }

            public static function random(int $length): string
            {
                return str_repeat('a', $length);
            }
        }
    }
}

namespace App\Jobs\Auth {
    if (!class_exists(CreateUser::class, false)) {
        class CreateUser
        {
            public function __construct(private array $payload)
            {
            }

            public function handle(): \Illuminate\Database\Eloquent\Model
            {
                return new \Illuminate\Database\Eloquent\Model(101);
            }
        }
    }

    if (!class_exists(UpdateUser::class, false)) {
        class UpdateUser
        {
            public function __construct(private \Illuminate\Database\Eloquent\Model $user, private array $payload)
            {
            }

            public function handle(): \Illuminate\Database\Eloquent\Model
            {
                return $this->user;
            }
        }
    }
}

namespace App\Models\Common {
    if (!class_exists(Company::class, false)) {
        class Company extends \Illuminate\Database\Eloquent\Model
        {
        }
    }
}

namespace Modules\Nfse\Tests\Unit\Console {
    use App\Models\Common\Company;
    use Illuminate\Database\Eloquent\Model;

    final class ProvisionTestUserTest extends \Modules\Nfse\Tests\TestCase
    {
        public function testHandleCreatesUserAndEmitsJsonPayload(): void
        {
            $command = new TestableProvisionTestUser();
            $command->testOptions = [
                'company-id' => '7',
                'json' => true,
                'landing-page' => 'dashboard',
                'name' => 'NFS-e E2E',
                'role' => 'admin',
            ];
            $command->company = new Company(7);
            $command->role = new Model(3);
            $command->createdUser = new Model(55);

            $exitCode = $command->handle();

            self::assertSame(0, $exitCode);
            self::assertCount(1, $command->createdPayloads);
            self::assertSame([
                'name' => 'NFS-e E2E',
                'email' => 'nfse-e2e+01testulid@example.test',
                'change_password' => true,
                'password' => 'NfseE2E!aaaaaaaaaaaaaaaaaa',
                'password_confirmation' => 'NfseE2E!aaaaaaaaaaaaaaaaaa',
                'companies' => [7],
                'roles' => '3',
                'landing_page' => 'dashboard',
                'enabled' => '1',
            ], $command->createdPayloads[0]);

            $payload = json_decode($command->outputLines[0], true, 512, JSON_THROW_ON_ERROR);

            self::assertTrue($payload['created']);
            self::assertSame('7', $payload['company_id']);
            self::assertSame('nfse-e2e+01testulid@example.test', $payload['email']);
            self::assertSame('NfseE2E!aaaaaaaaaaaaaaaaaa', $payload['password']);
            self::assertSame('3', $payload['role_id']);
            self::assertSame('55', $payload['user_id']);
        }

        public function testHandleUpdatesExistingUserWhenEmailAlreadyExists(): void
        {
            $existingUser = new Model(99);

            $command = new TestableProvisionTestUser();
            $command->testOptions = [
                'company-id' => '5',
                'email' => 'existing@example.test',
                'password' => 'custom-secret',
                'json' => true,
                'landing-page' => 'dashboard',
                'name' => 'Existing User',
                'role' => 'manager',
            ];
            $command->company = new Company(5);
            $command->role = new Model(8);
            $command->existingUser = $existingUser;
            $command->updatedUser = $existingUser;

            $exitCode = $command->handle();

            self::assertSame(0, $exitCode);
            self::assertCount(0, $command->createdPayloads);
            self::assertCount(1, $command->updatedPayloads);
            self::assertSame($existingUser, $command->updatedPayloads[0]['user']);
            self::assertSame('existing@example.test', $command->updatedPayloads[0]['payload']['email']);

            $payload = json_decode($command->outputLines[0], true, 512, JSON_THROW_ON_ERROR);

            self::assertFalse($payload['created']);
            self::assertSame('99', $payload['user_id']);
        }

        public function testHandleFailsWhenCompanyIsMissing(): void
        {
            $command = new TestableProvisionTestUser();
            $command->testOptions = [
                'company-id' => '404',
                'json' => true,
                'role' => 'admin',
            ];
            $command->role = new Model(2);

            $exitCode = $command->handle();

            self::assertSame(1, $exitCode);
            self::assertSame(['{"error":"Company 404 nao encontrada."}'], $command->outputLines);
        }
    }

    final class TestableProvisionTestUser extends \Modules\Nfse\Console\Commands\ProvisionTestUser
    {
        /** @var array<string, mixed> */
        public array $testOptions = [];

        public ?Company $company = null;

        public ?Model $role = null;

        public ?Model $existingUser = null;

        public ?Model $createdUser = null;

        public ?Model $updatedUser = null;

        /** @var list<array<string, mixed>> */
        public array $createdPayloads = [];

        /** @var list<array{user: Model, payload: array<string, mixed>}> */
        public array $updatedPayloads = [];

        protected function findCompanyById(int $companyId): ?Company
        {
            return $this->company;
        }

        protected function findRole(string $roleOption): ?Model
        {
            return $this->role;
        }

        protected function findUserByEmail(string $email): ?Model
        {
            return $this->existingUser;
        }

        protected function createUser(array $payload): Model
        {
            $this->createdPayloads[] = $payload;

            return $this->createdUser ?? new Model(1);
        }

        protected function updateUser(Model $user, array $payload): Model
        {
            $this->updatedPayloads[] = [
                'user' => $user,
                'payload' => $payload,
            ];

            return $this->updatedUser ?? $user;
        }
    }
}
