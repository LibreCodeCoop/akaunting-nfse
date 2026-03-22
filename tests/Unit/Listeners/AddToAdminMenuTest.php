<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    if (!function_exists('trans')) {
        function trans(string $key): string
        {
            return $key;
        }
    }
}

namespace App\Traits {
    if (!trait_exists(Modules::class, false)) {
        trait Modules
        {
            public function moduleIsEnabled(string $alias): bool
            {
                return true;
            }
        }
    }

    if (!trait_exists(Permissions::class, false)) {
        trait Permissions
        {
            public function canAccessMenuItem(string $title, string $permission): bool
            {
                return true;
            }
        }
    }
}

namespace App\Events\Menu {
    if (!class_exists(AdminCreated::class, false)) {
        class AdminCreated
        {
            public function __construct(public object $menu)
            {
            }
        }
    }
}

namespace Modules\Nfse\Tests\Unit\Listeners {
    use App\Events\Menu\AdminCreated;
    use Modules\Nfse\Listeners\AddToAdminMenu;
    use Modules\Nfse\Tests\TestCase;

    final class AddToAdminMenuTest extends TestCase
    {
        public function testHandleAddsDashboardRouteWhenModuleEnabledAndAccessAllowed(): void
        {
            $menu = new class () {
                /** @var array<int, array<string, mixed>> */
                public array $calls = [];

                /**
                 * @param array<int, mixed> $params
                 * @param array<string, mixed> $options
                 */
                public function route(string $route, string $title, array $params, int $order, array $options): void
                {
                    $this->calls[] = [
                        'route' => $route,
                        'title' => $title,
                        'params' => $params,
                        'order' => $order,
                        'options' => $options,
                    ];
                }
            };

            $listener = new class () extends AddToAdminMenu {
                public string $permission = '';

                public function moduleIsEnabled(string $alias): bool
                {
                    return true;
                }

                public function canAccessMenuItem(string $title, string $permission): bool
                {
                    $this->permission = $permission;

                    return true;
                }
            };

            $listener->handle(new AdminCreated($menu));

            self::assertCount(1, $menu->calls);
            self::assertSame('nfse.dashboard.index', $menu->calls[0]['route']);
            self::assertSame(45, $menu->calls[0]['order']);
            self::assertSame('receipt_long', $menu->calls[0]['options']['icon']);
            self::assertSame('read-settings-company', $listener->permission);
        }

        public function testHandleSkipsWhenModuleDisabled(): void
        {
            $menu = new class () {
                /** @var array<int, array<string, mixed>> */
                public array $calls = [];

                public function route(string $route, string $title, array $params, int $order, array $options): void
                {
                    $this->calls[] = [];
                }
            };

            $listener = new class () extends AddToAdminMenu {
                public function moduleIsEnabled(string $alias): bool
                {
                    return false;
                }
            };

            $listener->handle(new AdminCreated($menu));

            self::assertCount(0, $menu->calls);
        }

        public function testHandleSkipsWhenUserCannotAccessMenuItem(): void
        {
            $menu = new class () {
                /** @var array<int, array<string, mixed>> */
                public array $calls = [];

                public function route(string $route, string $title, array $params, int $order, array $options): void
                {
                    $this->calls[] = [];
                }
            };

            $listener = new class () extends AddToAdminMenu {
                public function moduleIsEnabled(string $alias): bool
                {
                    return true;
                }

                public function canAccessMenuItem(string $title, string $permission): bool
                {
                    return false;
                }
            };

            $listener->handle(new AdminCreated($menu));

            self::assertCount(0, $menu->calls);
        }
    }
}
