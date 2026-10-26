<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;

require_once __DIR__ . '/tests/integration/features/bootstrap/FeatureContext.php';
require_once __DIR__ . '/tests/integration/features/bootstrap/AkauntingHttpContext.php';
require_once __DIR__ . '/tests/integration/features/bootstrap/NfseApiContext.php';

return (new Config())
    ->withProfile((new Profile('default'))
        ->withSuite((new Suite('default'))
            ->withPaths('%paths.base%/tests/integration/features')
            ->withContexts('FeatureContext', 'NfseApiContext')));
