<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers {
    final class ControllerIsolationRedirector
    {
        public function back(): \Illuminate\Http\RedirectResponse
        {
            $response = new \Illuminate\Http\RedirectResponse();
            $response->target = 'back';

            return $response;
        }

        public function route(string $name, mixed ...$parameters): \Illuminate\Http\RedirectResponse
        {
            $response = new \Illuminate\Http\RedirectResponse();
            $response->target = 'route';
            $response->route = $name;
            $response->parameters = $parameters;

            return $response;
        }
    }
}
